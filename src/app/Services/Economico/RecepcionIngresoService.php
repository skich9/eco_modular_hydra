<?php

namespace App\Services\Economico;

use App\Models\RecepcionIngreso;
use App\Models\RecepcionIngresoDetalle;
use App\Models\Usuario;
use App\Services\LibroDiarioIdentificadorHelper;
use App\Services\Reportes\LibroDiarioAggregatorService;
use App\Services\Reportes\LibroDiarioCierreTotalesService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'cajas'                => DB::table('cajas_actividad')->orderBy('orden')->get(['id_caja_actividad', 'nombre_caja', 'prefijo'])->all(),
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

    private function formatearNombreCompleto(?string $nombre, ?string $apPaterno, ?string $apMaterno): string
    {
        $parts = array_filter(
            [trim((string) $nombre), trim((string) $apPaterno), trim((string) $apMaterno)],
            static fn (string $p) => $p !== ''
        );

        return implode(' ', $parts);
    }

    /**
     * Texto mostrado: nombre + apellido paterno + apellido materno; si faltan, nickname.
     */
    private function labelMostrarUsuario(?string $nombre, ?string $apPaterno, ?string $apMaterno, ?string $nickname): string
    {
        $full = $this->formatearNombreCompleto($nombre, $apPaterno, $apMaterno);
        if ($full !== '') {
            return $full;
        }
        $n = trim((string) $nickname);

        return $n !== '' ? $n : '—';
    }

    private function labelDesdeFilaCierre(object $c): string
    {
        $full = trim((string) ($c->nombre_completo ?? ''));
        if ($full !== '') {
            return $full;
        }
        $n = trim((string) ($c->usuario_nick ?? ''));

        return $n !== '' ? $n : '—';
    }

    private function compararPorNombreUsuario(object $a, object $b): int
    {
        $cmp = strcmp($this->labelDesdeFilaCierre($a), $this->labelDesdeFilaCierre($b));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a->usuario_nick ?? ''), (string) ($b->usuario_nick ?? ''));
    }

    private function mapearUsuariosSelectFirmas($collection): array
    {
        return $collection
            ->map(fn ($u) => [
                'id_usuario' => $u->id_usuario,
                'nickname'   => $u->nickname,
                'nombre'     => $u->nombre,
                'label'      => $this->labelMostrarUsuario($u->nombre, $u->ap_paterno, $u->ap_materno, $u->nickname),
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
            ->select(
                'usuarios.nombre',
                'usuarios.ap_paterno',
                'usuarios.ap_materno',
                'usuarios.nickname'
            )
            ->distinct()
            ->orderBy('usuarios.nombre')
            ->orderBy('usuarios.ap_paterno')
            ->get()
            ->map(fn ($r) => [
                'usuario' => $this->labelMostrarUsuario(
                    $r->nombre,
                    $r->ap_paterno,
                    $r->ap_materno,
                    $r->nickname
                ),
            ])
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
                'fecha_inicial_libros'    => $input['fecha_inicial_libros'] ?? null,
                'fecha_final_libros'      => $input['fecha_final_libros'] ?? null,
                'observacion'             => $input['observacion'] ?? null,
                'monto_total'             => $this->calcularMontoTotal($detalles),
                'id_actividad_economica'  => $input['id_actividad_economica'] ?? null,
                'id_caja_actividad'       => $this->resolverIdCaja($input),
                'es_ingreso_libro_diario' => filter_var($input['es_ingreso_libro_diario'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'anulado'                 => false,
            ]);

            // Crear los detalles (un registro por usuario_libro/libro diario)
            foreach ($detalles as $det) {
                $dep = (float) ($det['total_deposito'] ?? 0);
                $tras = (float) ($det['total_traspaso'] ?? 0);
                $rec = (float) ($det['total_recibos'] ?? 0);
                $fac = (float) ($det['total_facturas'] ?? 0);
                RecepcionIngresoDetalle::create([
                    'recepcion_ingreso_id' => $cabecera->id,
                    'usuario_libro'        => trim((string) ($det['usuario_libro'] ?? '')) ?: null,
                    'cod_libro_diario'     => trim((string) ($det['cod_libro_diario'] ?? '')) ?: null,
                    'fecha_inicial_libros' => $det['fecha_inicial_libros'] ?? null,
                    'fecha_final_libros'   => $det['fecha_final_libros'] ?? $fecha,
                    'total_deposito'       => $dep,
                    'total_traspaso'       => $tras,
                    'total_recibos'        => $rec,
                    'total_facturas'       => $fac,
                    'total_entregado'      => round($dep + $tras + $rec + $fac, 2),
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

    /**
     * Lista plana para la grilla tipo SGA `economico/lista_recepcion/get_list_recepcion`.
     * Sin paginación; `anulado` como 't'|'f' para la misma condición en el front que DataTables SGA.
     *
     * @return list<array<string, mixed>>
     */
    public function listarTablaRecepcionSga(): array
    {
        if (! Schema::hasTable('recepcion_ingresos')) {
            return [];
        }

        $aeJoin = Schema::hasTable('actividades_economicas');

        $q = DB::table('recepcion_ingresos as r')
            ->orderByDesc('r.fecha_recepcion')
            ->orderByDesc('r.id');

        if ($aeJoin) {
            $q->leftJoin(
                'actividades_economicas as ae',
                'r.id_actividad_economica',
                '=',
                'ae.id_actividad_economica'
            );
        }

        $select = [
            'r.id as id_recepcion',
            'r.fecha_recepcion',
            'r.usuario_entregue1',
            'r.usuario_recibi1',
            'r.usuario_entregue2',
            'r.usuario_recibi2',
            'r.cod_documento',
            'r.observacion',
            'r.monto_total',
            'r.anulado',
            'r.motivo_anulacion',
            $aeJoin
                ? DB::raw("COALESCE(ae.nombre, '') as nombre_caja")
                : DB::raw("'' as nombre_caja"),
        ];

        $rows = $q->select($select)->get();

        $mapNombrePorNickname = $this->mapaNombreCompletoPorNicknameRecepcion($rows);

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $fecha = $row['fecha_recepcion'] ?? null;
            $fechaStr = $fecha
                ? Carbon::parse((string) $fecha)->format('Y-m-d')
                : '';

            $rawAnul = $row['anulado'] ?? false;
            $anul = $rawAnul === true
                || $rawAnul === 1
                || $rawAnul === '1'
                || $rawAnul === 't'
                || $rawAnul === 'true';

            $out[] = [
                'id_recepcion'       => (int) ($row['id_recepcion'] ?? 0),
                'fecha_recepcion'    => $fechaStr,
                'nombre_caja'        => (string) ($row['nombre_caja'] ?? ''),
                'usuario_entregue1'  => $this->etiquetaFirmaRecepcionLista((string) ($row['usuario_entregue1'] ?? ''), $mapNombrePorNickname),
                'usuario_recibi1'    => $this->etiquetaFirmaRecepcionLista((string) ($row['usuario_recibi1'] ?? ''), $mapNombrePorNickname),
                'usuario_entregue2'  => $this->etiquetaFirmaRecepcionLista((string) ($row['usuario_entregue2'] ?? ''), $mapNombrePorNickname),
                'usuario_recibi2'    => $this->etiquetaFirmaRecepcionLista((string) ($row['usuario_recibi2'] ?? ''), $mapNombrePorNickname),
                'cod_documento'      => (string) ($row['cod_documento'] ?? ''),
                'observacion'        => $row['observacion'] !== null ? (string) $row['observacion'] : '',
                'monto_total'        => $row['monto_total'] !== null ? (float) $row['monto_total'] : null,
                'anulado'            => $anul ? 't' : 'f',
                'motivo_anulacion'   => $row['motivo_anulacion'] !== null ? (string) $row['motivo_anulacion'] : '',
            ];
        }

        return $out;
    }

    /**
     * Los campos de firma en `recepcion_ingresos` guardan el nickname (selector del front).
     * Para la lista se expone nombre + apellidos como en SGA.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $filasRecepcion
     * @return array<string, string>  nickname normalizado => nombre completo
     */
    private function mapaNombreCompletoPorNicknameRecepcion(Collection $filasRecepcion): array
    {
        if (! Schema::hasTable('usuarios') || $filasRecepcion->isEmpty()) {
            return [];
        }

        $clavePorNickname = [];
        foreach ($filasRecepcion as $fila) {
            foreach (['usuario_entregue1', 'usuario_recibi1', 'usuario_entregue2', 'usuario_recibi2'] as $col) {
                $k = Usuario::normalizeNickname((string) ($fila->{$col} ?? ''));
                if ($k !== '') {
                    $clavePorNickname[$k] = true;
                }
            }
        }

        $nicknames = array_keys($clavePorNickname);
        if ($nicknames === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($nicknames, 500) as $chunk) {
            $usuarios = DB::table('usuarios')
                ->whereIn('nickname', $chunk)
                ->get(['nickname', 'nombre', 'ap_paterno', 'ap_materno']);

            foreach ($usuarios as $u) {
                $nk = Usuario::normalizeNickname((string) $u->nickname);
                $map[$nk] = $this->nombreCompletoUsuarioDesdePartes($u->nombre, $u->ap_paterno, $u->ap_materno);
            }
        }

        return $map;
    }

    private function nombreCompletoUsuarioDesdePartes($nombre, $apPaterno, $apMaterno): string
    {
        $parts = [];
        foreach ([$nombre, $apPaterno, $apMaterno] as $p) {
            $t = trim((string) ($p ?? ''));
            if ($t !== '') {
                $parts[] = $t;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, string>  $mapNombrePorNickname
     */
    private function etiquetaFirmaRecepcionLista(string $valorGuardado, array $mapNombrePorNickname): string
    {
        $v = trim($valorGuardado);
        if ($v === '') {
            return '';
        }
        $k = Usuario::normalizeNickname($v);

        return $mapNombrePorNickname[$k] ?? $v;
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

        // Cerrar automáticamente los libros olvidados de fechas anteriores a hoy
        if ($carrera !== '') {
            $this->autoCerrarLibrosOlvidados($carrera, $fechaDesde, $fechaHasta);
        }

        $prefijoLibro = 'RD-' . $carrera . '-';

        $detallesParaReporte = [];

        if (Schema::hasTable('libro_diario_cierre')) {
            $queryLibro = DB::table('libro_diario_cierre')
                ->join('usuarios', 'libro_diario_cierre.id_usuario', '=', 'usuarios.id_usuario')
                ->select(
                    'libro_diario_cierre.id as id_cierre',
                    DB::raw("NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(usuarios.nombre), ''), NULLIF(TRIM(usuarios.ap_paterno), ''), NULLIF(TRIM(usuarios.ap_materno), ''))), '') as nombre_completo"),
                    'usuarios.nickname as usuario_nick',
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
                ->orderBy('usuarios.nombre')
                ->orderBy('usuarios.ap_paterno')
                ->orderBy('usuarios.ap_materno')
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
                    $u = $this->compararPorNombreUsuario($a, $b);
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
                    'usuario_libro'        => $this->labelDesdeFilaCierre($c),
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
     * Monto total cabecera = suma de las cuatro columnas de cada detalle (misma regla que la tabla ING-4).
     *
     * @param  array<int, array<string, mixed>>  $detalles
     */
    private function calcularMontoTotal(array $detalles): float
    {
        $s = 0.0;
        foreach ($detalles as $d) {
            if (! is_array($d)) {
                continue;
            }
            $s += (float) ($d['total_deposito'] ?? 0)
                + (float) ($d['total_traspaso'] ?? 0)
                + (float) ($d['total_recibos'] ?? 0)
                + (float) ($d['total_facturas'] ?? 0);
        }

        return round($s, 2);
    }

    /**
     * Resuelve id_caja_actividad para una recepción:
     * - Si viene explícito en el input, lo usa directamente.
     * - Si no, lo busca a través de actividades_economicas (igual que el SGA).
     */
    private function resolverIdCaja(array $input): ?int
    {
        if (!empty($input['id_caja_actividad'])) {
            return (int) $input['id_caja_actividad'];
        }

        $idActividad = $input['id_actividad_economica'] ?? null;
        if ($idActividad) {
            $caja = DB::table('actividades_economicas')
                ->where('id_actividad_economica', $idActividad)
                ->value('id_caja_actividad');

            if ($caja) {
                return (int) $caja;
            }
        }

        // Fallback: deducir por prefijo de caja usando codigo_carrera
        $carrera = strtoupper(trim((string) ($input['codigo_carrera'] ?? '')));
        if ($carrera !== '') {
            $caja = DB::table('cajas_actividad')
                ->where('prefijo', $carrera)
                ->value('id_caja_actividad');

            if ($caja) {
                return (int) $caja;
            }
        }

        Log::warning('[RecepcionIngreso] No se pudo resolver id_caja_actividad', [
            'id_actividad_economica' => $idActividad,
            'codigo_carrera'         => $input['codigo_carrera'] ?? null,
        ]);

        return null;
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
                DB::raw("NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(usuarios.nombre), ''), NULLIF(TRIM(usuarios.ap_paterno), ''), NULLIF(TRIM(usuarios.ap_materno), ''))), '') as nombre_completo"),
                'usuarios.nickname as usuario_nick',
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

    /**
     * Cierra automáticamente los libros diarios olvidados para fechas anteriores a hoy.
     * Solo actúa sobre fechas estrictamente menores a hoy (nunca cierra el día en curso).
     * Se llama al inicio de datosParaReporte() para que el contador vea todos los libros al consultar.
     */
    private function autoCerrarLibrosOlvidados(string $carrera, ?string $fechaDesde, ?string $fechaHasta): void
    {
        if (! Schema::hasTable('cobro') || ! Schema::hasTable('libro_diario_cierre')) {
            return;
        }

        $hoy  = Carbon::today(self::TZ_NEGOCIO)->format('Y-m-d');
        $desde = $fechaDesde ?? '2000-01-01';
        $hasta = $fechaHasta ?? $hoy;

        // Limitar hasta a ayer como máximo (nunca cerrar el día en curso)
        if ($hasta >= $hoy) {
            $hasta = Carbon::yesterday(self::TZ_NEGOCIO)->format('Y-m-d');
        }

        // Si el rango quedó vacío (ej.: consultan solo el día de hoy) no hay nada que hacer
        if ($desde > $hasta) {
            return;
        }

        // Pares (id_usuario, fecha) con cobros registrados en el rango y carrera dados
        $conTransacciones = DB::table('cobro')
            ->select('id_usuario', DB::raw('DATE(fecha_cobro) as fecha'))
            ->whereDate('fecha_cobro', '>=', $desde)
            ->whereDate('fecha_cobro', '<=', $hasta)
            ->whereIn('cod_pensum', function ($sub) use ($carrera) {
                $sub->select('cod_pensum')->from('pensums')->where('codigo_carrera', $carrera);
            })
            ->distinct()
            ->get();

        if ($conTransacciones->isEmpty()) {
            return;
        }

        foreach ($conTransacciones as $row) {
            $idUsuario = (int) $row->id_usuario;
            $fecha     = (string) $row->fecha;

            // Verificar si ya existe un cierre para ese usuario/fecha (cualquier carrera o nulo)
            $yaCerrado = DB::table('libro_diario_cierre')
                ->where('id_usuario', $idUsuario)
                ->where('fecha', $fecha)
                ->where(function ($q) use ($carrera) {
                    $q->where('codigo_carrera', $carrera)
                      ->orWhereNull('codigo_carrera')
                      ->orWhere('codigo_carrera', '');
                })
                ->exists();

            if ($yaCerrado) {
                continue;
            }

            try {
                DB::transaction(function () use ($idUsuario, $fecha, $carrera) {
                    $identificador = LibroDiarioIdentificadorHelper::reservarSiguienteIdentificador($fecha, $carrera);

                    $ordenCierre = DB::table('libro_diario_cierre')
                        ->where('id_usuario', $idUsuario)
                        ->where('fecha', $fecha)
                        ->max('orden_cierre');
                    $ordenCierre = $ordenCierre !== null ? (int) $ordenCierre + 1 : 1;

                    $idCierre = DB::table('libro_diario_cierre')->insertGetId([
                        'id_usuario'     => $idUsuario,
                        'fecha'          => $fecha,
                        'orden_cierre'   => $ordenCierre,
                        'codigo_carrera' => $carrera,
                        'hora_cierre'    => '23:55:00',
                        'correlativo'    => $identificador['correlativo'],
                        'codigo_rd'      => $identificador['codigo_rd'],
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    $this->cierreTotales->syncFromCierreId($idCierre, $carrera);
                });
            } catch (\Throwable $e) {
                Log::warning('[AutoCierre] No se pudo cerrar libro diario olvidado', [
                    'id_usuario' => $idUsuario,
                    'fecha'      => $fecha,
                    'carrera'    => $carrera,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
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
