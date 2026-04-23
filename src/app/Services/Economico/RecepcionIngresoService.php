<?php

namespace App\Services\Economico;

use App\Models\RecepcionIngreso;
use App\Models\RecepcionIngresoDetalle;
use App\Models\Usuario;
use App\Services\Reportes\LibroDiarioAggregatorService;
use App\Services\Reportes\LibroDiarioCierreTotalesService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecepcionIngresoService
{
    private const TZ_NEGOCIO = 'America/La_Paz';
    private const ID_ROL_TESORERIA = 9;

    public function __construct(
        private readonly LibroDiarioCierreTotalesService $cierreTotales,
        private readonly LibroDiarioAggregatorService $libroDiarioAggregator
    ) {
    }

    /** Hora local de Bolivia */
    private function ahoraNegocio(): Carbon
    {
        return Carbon::now(self::TZ_NEGOCIO);
    }

    // ─── Datos iniciales para el formulario ──────────────────────────────────

    /**
     * Retorna todos los datos de catálogo que necesita el formulario al cargar.
     */
    public function initialData(): array
    {
        $firmas = $this->listUsuariosFirmasRecepcionSga();

        return [
            'carreras'             => $this->listCarreras(),
            'actividades'          => $this->listActividades(),
            // Mismo listado para los 4 select (alineado a SGA: contabilidad/secretaría/tesorería + aux. finanzas)
            'tesoreros'            => $firmas,
            'usuarios_activos'     => $firmas,
            'usuarios_libros'      => $this->listUsuariosLibrosDiarios(),
        ];
    }

    /**
     * Carreras disponibles en el sistema (EEA y MEA).
     * Se construye de forma estática ya que son las dos únicas carreras de CETA.
     */
    public function listCarreras(): array
    {
        return [
            ['codigo' => 'EEA', 'nombre' => 'Electrónica Automotriz'],
            ['codigo' => 'MEA', 'nombre' => 'Mecánica Automotriz'],
        ];
    }

    /**
     * Actividades económicas disponibles (sincronizadas desde SIN).
     */
    public function listActividades(): array
    {
        if (!Schema::hasTable('actividades_economicas')) {
            return [];
        }

        return DB::table('actividades_economicas')
            ->orderBy('orden')
            ->orderBy('id_actividad_economica')
            ->get(['id_actividad_economica as id', 'nombre as descripcion'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Misma lógica que SGA `economico/recepcion_ingresos`: los 4 select (Entregue/Recibi) comparten
     * `join_lista( roles contabilidad+secretaría+tesorería , cargo AUXILIAR ADMINISTRATIVO FINANCIERO )`.
     * En Eco: roles vía `rol.nombre` y, si existe columna, `apoyoCobranzas` como aprox. del cargo auxiliar.
     */
    public function listUsuariosFirmasRecepcionSga(): array
    {
        $idsRol = $this->idsRolFirmasRecepcionSga();
        $q = Usuario::query()
            ->where('estado', 1)
            ->orderBy('nombre');

        $q->where(function ($w) use ($idsRol) {
            if ($idsRol !== []) {
                $w->whereIn('id_rol', $idsRol);
            }
            if (Schema::hasColumn('usuarios', 'apoyoCobranzas')) {
                $w->orWhere('apoyoCobranzas', true);
            }
            if ($idsRol === [] && ! Schema::hasColumn('usuarios', 'apoyoCobranzas')) {
                $w->whereRaw('0 = 1');
            }
        });

        $rows = $q->get();
        if ($rows->isEmpty()) {
            return $this->listUsuariosActivos();
        }

        return $this->mapearUsuariosSelectFirmas($rows);
    }

    /**
     * @return int[]
     */
    private function idsRolFirmasRecepcionSga(): array
    {
        if (! Schema::hasTable('rol')) {
            return [];
        }
        $ids = DB::table('rol')->pluck('nombre', 'id_rol');

        $out = [];
        foreach ($ids as $idRol => $nombre) {
            $n = $this->normalizarNombreRolSga((string) $nombre);
            if (in_array($n, ['contador', 'secretaria', 'tesoreria'], true)) {
                $out[] = (int) $idRol;
            }
        }
        if ($out === [] && DB::table('rol')->where('id_rol', self::ID_ROL_TESORERIA)->exists()) {
            return [self::ID_ROL_TESORERIA];
        }

        return array_values(array_unique($out));
    }

    private function normalizarNombreRolSga(string $nombre): string
    {
        $n = mb_strtolower(trim($nombre), 'UTF-8');

        return str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü'], ['a', 'e', 'i', 'o', 'u', 'u'], $n);
    }

    private function mapearUsuariosSelectFirmas($collection): array
    {
        return $collection
            ->map(fn ($u) => [
                'id_usuario' => $u->id_usuario,
                'nickname'   => $u->nickname,
                'nombre'     => $u->nombre,
                'label'      => $u->nombre . ' (' . $u->nickname . ')',
            ])
            ->values()
            ->all();
    }

    /**
     * Todos los usuarios activos (respaldo si el filtro SGA no devuelve filas).
     */
    public function listUsuariosActivos(): array
    {
        $rows = Usuario::query()
            ->where('estado', 1)
            ->orderBy('nombre')
            ->get();

        return $this->mapearUsuariosSelectFirmas($rows);
    }

    /**
     * @deprecated  Usar listUsuariosFirmasRecepcionSga; se mantiene para compatibilidad.
     */
    public function listTesoreros(): array
    {
        return $this->listUsuariosFirmasRecepcionSga();
    }

    /**
     * Usuarios que aparecen como usuario_libro en libro_diario_cierre, para
     * autocompletar los detalles de la recepción.
     */
    public function listUsuariosLibrosDiarios(): array
    {
        if (!Schema::hasTable('libro_diario_cierre')) {
            return [];
        }

        return DB::table('libro_diario_cierre')
            ->join('usuarios', 'libro_diario_cierre.id_usuario', '=', 'usuarios.id_usuario')
            ->select('usuarios.nickname as usuario')
            ->distinct()
            ->orderBy('usuario')
            ->pluck('usuario')
            ->map(fn ($u) => ['usuario' => $u])
            ->values()
            ->all();
    }

    // ─── Correlativo ─────────────────────────────────────────────────────────

    /**
     * Genera el siguiente número de documento para la carrera y mes dados.
     *
     * El código documento sigue el patrón: {CARRERA}-{MES_DOS_DIGITOS}-{NUM_TRES_DIGITOS}
     * Ejemplo: EEA-04-029
     *
     * @param  string  $carrera  'EEA' | 'MEA'
     * @param  string  $fecha    Fecha en formato Y-m-d (usa el mes de esa fecha)
     */
    public function siguienteNumDocumento(string $carrera, string $fecha): array
    {
        $mes = Carbon::parse($fecha)->format('m');
        $prefijo = strtoupper(trim($carrera)) . '-' . $mes . '-';

        // Buscar el máximo num_documento para esa carrera y ese mes
        $max = DB::table('recepcion_ingresos')
            ->where('codigo_carrera', strtoupper($carrera))
            ->where('cod_documento', 'like', $prefijo . '%')
            ->max('num_documento');

        $siguiente = $max !== null ? (int) $max + 1 : 1;
        $codDocumento = $prefijo . str_pad($siguiente, 3, '0', STR_PAD_LEFT);

        return [
            'num_documento' => $siguiente,
            'cod_documento' => $codDocumento,
        ];
    }

    // ─── Registro ─────────────────────────────────────────────────────────────

    /**
     * Crea una recepción con sus detalles dentro de una transacción.
     *
     * @param  array<string, mixed>  $input
     */
    public function registrar(array $input, Usuario $usuario): array
    {
        $carrera   = strtoupper(trim((string) ($input['codigo_carrera'] ?? '')));
        $fecha     = Carbon::parse($input['fecha_recepcion'] ?? now())->format('Y-m-d');
        $detalles  = $input['detalles'] ?? [];

        return DB::transaction(function () use ($input, $carrera, $fecha, $detalles, $usuario) {

            // Correlativo con bloqueo para evitar colisiones concurrentes
            $correlativo = $this->siguienteNumDocumentoBloqueado($carrera, $fecha);

            $cabecera = RecepcionIngreso::create([
                'codigo_carrera'          => $carrera,
                'fecha_recepcion'         => $fecha,
                'fecha_registro'          => $this->ahoraNegocio(),
                'usuario_entregue1'       => trim((string) ($input['usuario_entregue1'] ?? '')),
                'usuario_recibi1'         => trim((string) ($input['usuario_recibi1'] ?? '')),
                'usuario_entregue2'       => trim((string) ($input['usuario_entregue2'] ?? '')) ?: null,
                'usuario_recibi2'         => trim((string) ($input['usuario_recibi2'] ?? '')) ?: null,
                'usuario_registro'        => $usuario->nickname ?? (string) $usuario->id_usuario,
                'cod_documento'           => $correlativo['cod_documento'],
                'num_documento'           => $correlativo['num_documento'],
                'observacion'             => $input['observacion'] ?? null,
                'monto_total'             => $this->calcularMontoTotal($detalles),
                'id_actividad_economica'  => $input['id_actividad_economica'] ?? null,
                'es_ingreso_libro_diario' => filter_var($input['es_ingreso_libro_diario'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'anulado'                 => false,
            ]);

            // Crear los detalles (un registro por usuario_libro/libro diario)
            foreach ($detalles as $det) {
                RecepcionIngresoDetalle::create([
                    'recepcion_ingreso_id' => $cabecera->id,
                    'usuario_libro'        => trim((string) ($det['usuario_libro'] ?? '')) ?: null,
                    'cod_libro_diario'     => trim((string) ($det['cod_libro_diario'] ?? '')) ?: null,
                    'fecha_inicial_libros' => $det['fecha_inicial_libros'] ?? null,
                    'fecha_final_libros'   => $det['fecha_final_libros'] ?? $fecha,
                    'total_deposito'       => (float) ($det['total_deposito'] ?? 0),
                    'total_traspaso'       => (float) ($det['total_traspaso'] ?? 0),
                    'total_recibos'        => (float) ($det['total_recibos'] ?? 0),
                    'total_facturas'       => (float) ($det['total_facturas'] ?? 0),
                    'total_entregado'      => (float) ($det['total_entregado'] ?? 0),
                    'faltante_sobrante'    => isset($det['faltante_sobrante']) ? (float) $det['faltante_sobrante'] : null,
                ]);
            }

            return [
                'id'           => $cabecera->id,
                'cod_documento' => $cabecera->cod_documento,
                'num_documento' => $cabecera->num_documento,
                'monto_total'  => $cabecera->monto_total,
            ];
        });
    }

    // ─── Listado / búsqueda ───────────────────────────────────────────────────

    /**
     * Lista recepciones con filtros opcionales, paginadas.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function listar(array $filtros): array
    {
        $query = RecepcionIngreso::with('detalles')
            ->orderByDesc('fecha_recepcion')
            ->orderByDesc('id');

        if (!empty($filtros['codigo_carrera'])) {
            $query->where('codigo_carrera', strtoupper($filtros['codigo_carrera']));
        }

        if (!empty($filtros['fecha_desde'])) {
            $query->where('fecha_recepcion', '>=', $filtros['fecha_desde']);
        }

        if (!empty($filtros['fecha_hasta'])) {
            $query->where('fecha_recepcion', '<=', $filtros['fecha_hasta']);
        }

        if (!empty($filtros['id_actividad_economica'])) {
            $query->where('id_actividad_economica', $filtros['id_actividad_economica']);
        }

        if (isset($filtros['anulado'])) {
            $query->where('anulado', (bool) $filtros['anulado']);
        }

        $perPage = (int) ($filtros['per_page'] ?? 25);

        return $query->paginate($perPage)->toArray();
    }

    // ─── Generar reporte ──────────────────────────────────────────────────────

    /**
     * Construye los datos del reporte de recepción: un renglón por cierre de libro diario
     * (libro_diario_cierre) con importes reales (Libro Diario) persistidos o calculados
     * en `libro_diario_cierre_totales`. Filtro de actividad: tabla pivote
     * `usuario_actividad_economica` con reserva a `usuarios.id_actividad_economica` legacy.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function datosParaReporte(array $filtros): array
    {
        $carrera        = strtoupper(trim((string) ($filtros['codigo_carrera'] ?? '')));
        $fechaDesde     = $filtros['fecha_desde'] ?? null;
        $fechaHasta     = $filtros['fecha_hasta'] ?? null;
        $idActividad    = $filtros['id_actividad_economica'] ?? null;
        $entregue1      = trim((string) ($filtros['usuario_entregue1'] ?? ''));
        $recibi1        = trim((string) ($filtros['usuario_recibi1'] ?? ''));
        $entregue2      = trim((string) ($filtros['usuario_entregue2'] ?? '')) ?: null;
        $recibi2        = trim((string) ($filtros['usuario_recibi2'] ?? '')) ?: null;

        $prefijoLibro = 'RD-' . $carrera . '-';

        $detallesParaReporte = [];

        if (Schema::hasTable('libro_diario_cierre')) {
            $queryLibro = DB::table('libro_diario_cierre')
                ->join('usuarios', 'libro_diario_cierre.id_usuario', '=', 'usuarios.id_usuario')
                ->select(
                    'libro_diario_cierre.id as id_cierre',
                    'usuarios.nickname as usuario',
                    'libro_diario_cierre.codigo_rd',
                    'libro_diario_cierre.fecha as fecha_cierre',
                    'libro_diario_cierre.codigo_carrera as cierre_codigo_carrera'
                )
                // Libro diario y cierre de caja guardan carrera en `codigo_carrera`; el código RD
                // puede faltar, ser RD-S/N-… si no se envió carrera al cerrar, o no coincidir aún
                // con RD-{carrera}-. El reporte debe alinearse al libro diario por carrera explícita.
                ->where(function ($q) use ($carrera, $prefijoLibro) {
                    $q->where('libro_diario_cierre.codigo_rd', 'like', $prefijoLibro . '%');
                    if (Schema::hasColumn('libro_diario_cierre', 'codigo_carrera')) {
                        $q->orWhereRaw(
                            'UPPER(TRIM(COALESCE(libro_diario_cierre.codigo_carrera, ""))) = ?',
                            [$carrera]
                        );
                    }
                })
                ->orderBy('usuarios.nickname')
                ->orderBy('libro_diario_cierre.fecha')
                ->orderBy('libro_diario_cierre.id');

            $this->aplicarFiltroFechasYActividadAQueryRecepcion($queryLibro, $fechaDesde, $fechaHasta, $idActividad);

            $cierresEstrictos = $queryLibro->get();
            $idsEstrictos = $cierresEstrictos->pluck('id_cierre')->all();

            $cierresComplemento = $this->cierresComplementoPorAgregador(
                $carrera,
                $fechaDesde,
                $fechaHasta,
                $idActividad,
                $idsEstrictos
            );

            $cierres = $cierresEstrictos
                ->merge($cierresComplemento)
                ->unique('id_cierre')
                ->sort(function ($a, $b) {
                    $u = strcmp((string) ($a->usuario ?? ''), (string) ($b->usuario ?? ''));
                    if ($u !== 0) {
                        return $u;
                    }
                    $f = strcmp((string) ($a->fecha_cierre ?? ''), (string) ($b->fecha_cierre ?? ''));
                    if ($f !== 0) {
                        return $f;
                    }

                    return ((int) ($a->id_cierre ?? 0)) <=> ((int) ($b->id_cierre ?? 0));
                })
                ->values();

            foreach ($cierres as $c) {
                $carreraF = trim((string) ($c->cierre_codigo_carrera ?? '')) !== ''
                    ? trim((string) $c->cierre_codigo_carrera)
                    : $carrera;
                $tot = $this->cierreTotales->obtenerOComputar((int) $c->id_cierre, $carreraF);
                $fechaC = (string) $c->fecha_cierre;
                $detallesParaReporte[] = [
                    'usuario_libro'        => (string) ($c->usuario ?? '—'),
                    'cod_libro_diario'     => (string) ($c->codigo_rd ?? ''),
                    'fecha_inicial_libros' => $fechaC,
                    'fecha_final_libros'   => $fechaC,
                    'total_deposito'       => $tot['total_deposito'],
                    'total_traspaso'       => $tot['total_traspaso'],
                    'total_recibos'        => $tot['total_recibos'],
                    'total_facturas'       => $tot['total_facturas'],
                    'total_entregado'      => $tot['total_entregado'],
                    'faltante_sobrante'    => null,
                ];
            }
        }

        $montoTotal = array_sum(array_column($detallesParaReporte, 'total_entregado'));

        return [
            // Encabezado del documento
            'codigo_carrera'         => $carrera,
            'carrera_nombre'         => $carrera === 'EEA' ? 'Electrónica Automotriz' : 'Mecánica Automotriz',
            'fecha_recepcion'        => $fechaHasta ?? $this->ahoraNegocio()->format('Y-m-d'),
            'fecha_desde'            => $fechaDesde,
            'fecha_hasta'            => $fechaHasta,
            'id_actividad_economica' => $idActividad,
            // Participantes
            'usuario_entregue1'      => $entregue1,
            'usuario_recibi1'        => $recibi1,
            'usuario_entregue2'      => $entregue2,
            'usuario_recibi2'        => $recibi2,
            // Totales y detalles
            'monto_total'            => $montoTotal,
            'detalles'               => $detallesParaReporte,
            // Bandera para el PDF: solo mostrar segunda fila si entregue2/recibi2 tienen valor
            'mostrar_segunda_fila'   => ($entregue2 !== null && $recibi2 !== null),
        ];
    }

    // ─── Anulación ────────────────────────────────────────────────────────────

    /**
     * Anula una recepción por ID.
     */
    public function anular(int $id, string $motivo): bool
    {
        $recepcion = RecepcionIngreso::find($id);

        if (!$recepcion || $recepcion->anulado) {
            return false;
        }

        return (bool) $recepcion->update([
            'anulado'          => true,
            'motivo_anulacion' => trim($motivo),
        ]);
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Calcula el monto total sumando total_entregado de todos los detalles.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     */
    private function calcularMontoTotal(array $detalles): float
    {
        return array_sum(array_column($detalles, 'total_entregado'));
    }

    /**
     * Genera el correlativo dentro de una transacción con bloqueo (sin colisiones).
     */
    private function siguienteNumDocumentoBloqueado(string $carrera, string $fecha): array
    {
        $mes     = Carbon::parse($fecha)->format('m');
        $prefijo = strtoupper($carrera) . '-' . $mes . '-';

        $max = DB::table('recepcion_ingresos')
            ->lockForUpdate()
            ->where('codigo_carrera', strtoupper($carrera))
            ->where('cod_documento', 'like', $prefijo . '%')
            ->max('num_documento');

        $siguiente    = $max !== null ? (int) $max + 1 : 1;
        $codDocumento = $prefijo . str_pad($siguiente, 3, '0', STR_PAD_LEFT);

        return [
            'num_documento' => $siguiente,
            'cod_documento' => $codDocumento,
        ];
    }

    private function aplicarFiltroFechasYActividadAQueryRecepcion(
        Builder $query,
        $fechaDesde,
        $fechaHasta,
        $idActividad
    ): void {
        if ($fechaDesde) {
            $query->where('libro_diario_cierre.fecha', '>=', $fechaDesde);
        }
        if ($fechaHasta) {
            $query->where('libro_diario_cierre.fecha', '<=', $fechaHasta);
        }

        if ($idActividad) {
            $idA = (int) $idActividad;
            if (Schema::hasTable('usuario_actividad_economica')) {
                $query->where(function ($q) use ($idA) {
                    $q->whereExists(function ($sub) use ($idA) {
                        $sub->from('usuario_actividad_economica as uae')
                            ->whereColumn('uae.id_usuario', 'usuarios.id_usuario')
                            ->where('uae.id_actividad_economica', $idA)
                            ->where('uae.activo', true);
                    });
                    $q->orWhere(function ($inner) use ($idA) {
                        $inner->whereNotExists(function ($sub) {
                            $sub->from('usuario_actividad_economica as uae')
                                ->whereColumn('uae.id_usuario', 'usuarios.id_usuario');
                        });
                        if (Schema::hasColumn('usuarios', 'id_actividad_economica')) {
                            $inner->where('usuarios.id_actividad_economica', $idA);
                        } else {
                            $inner->whereRaw('1 = 0');
                        }
                    });
                });
            } elseif (Schema::hasColumn('usuarios', 'id_actividad_economica')) {
                $query->where('usuarios.id_actividad_economica', $idA);
            }
        }
    }

    /**
     * Cierres no etiquetados con RD-EEA-/RD-MEA-/codigo_carrera, o con S/N, que aun así tienen
     * movimientos en Libro Diario para la carrera pedida (misma regla que el agregador al imprimir).
     */
    private function cierresComplementoPorAgregador(
        string $carrera,
        $fechaDesde,
        $fechaHasta,
        $idActividad,
        array $idsEstrictos
    ): Collection {
        $q = DB::table('libro_diario_cierre')
            ->join('usuarios', 'libro_diario_cierre.id_usuario', '=', 'usuarios.id_usuario')
            ->select(
                'libro_diario_cierre.id as id_cierre',
                'libro_diario_cierre.id_usuario',
                'usuarios.nickname as usuario',
                'libro_diario_cierre.codigo_rd',
                'libro_diario_cierre.fecha as fecha_cierre',
                'libro_diario_cierre.codigo_carrera as cierre_codigo_carrera'
            )
            ->where(function ($q2) {
                $q2->whereNull('libro_diario_cierre.codigo_carrera')
                    ->orWhereRaw('TRIM(COALESCE(libro_diario_cierre.codigo_carrera, "")) = ?', ['']);
                $q2->orWhere('libro_diario_cierre.codigo_rd', 'like', 'RD-S/N%');
                $q2->orWhereNull('libro_diario_cierre.codigo_rd');
            });

        if ($idsEstrictos !== []) {
            $q->whereNotIn('libro_diario_cierre.id', $idsEstrictos);
        }

        $this->aplicarFiltroFechasYActividadAQueryRecepcion($q, $fechaDesde, $fechaHasta, $idActividad);

        $out = collect();
        foreach ($q->get() as $row) {
            if (! $this->cierreTieneMovimientosCarreraEnLibroDiario(
                (int) $row->id_usuario,
                (string) $row->fecha_cierre,
                $carrera
            )) {
                continue;
            }
            $out->push($row);
        }

        return $out;
    }

    private function cierreTieneMovimientosCarreraEnLibroDiario(int $idUsuario, string $fechaYmd, string $carrera): bool
    {
        if ($idUsuario <= 0 || $carrera === '' || $fechaYmd === '') {
            return false;
        }
        try {
            $r = $this->libroDiarioAggregator->build([
                'id_usuario'      => $idUsuario,
                'fecha_inicio'    => $fechaYmd,
                'fecha_fin'       => $fechaYmd,
                'codigo_carrera'  => $carrera,
                'usuario_display' => '',
            ]);
            $g = (float) ($r['resumen']['total_general'] ?? 0);
            $i = (float) ($r['totales']['ingresos'] ?? 0);
            if ($g > 0.0001 || $i > 0.0001) {
                return true;
            }
            $datos = $r['datos'] ?? [];

            return is_array($datos) && count($datos) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
