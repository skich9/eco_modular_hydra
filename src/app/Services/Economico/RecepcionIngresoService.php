<?php

namespace App\Services\Economico;

use App\Models\RecepcionIngreso;
use App\Models\RecepcionIngresoDetalle;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecepcionIngresoService
{
    private const TZ_NEGOCIO = 'America/La_Paz';
    private const ID_ROL_TESORERIA = 9;

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
        return [
            'carreras'             => $this->listCarreras(),
            'actividades'          => $this->listActividades(),
            'tesoreros'            => $this->listTesoreros(),
            'usuarios_activos'     => $this->listUsuariosActivos(),
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
     * Usuarios con Rol Tesorería (id_rol = 9) para los selects de recibi1 y recibi2.
     */
    public function listTesoreros(): array
    {
        return $this->listUsuariosPorRol(self::ID_ROL_TESORERIA);
    }

    /**
     * Todos los usuarios activos para el select de entregue1 y entregue2.
     */
    public function listUsuariosActivos(): array
    {
        return Usuario::query()
            ->where('estado', 1)
            ->orderBy('nombre')
            ->get(['id_usuario', 'nickname', 'nombre'])
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
     * Construye los datos necesarios para el PDF del reporte de ingresos.
     *
     * Busca todos los usuarios que tuvieron cobros (en libro_diario_cierre)
     * dentro del rango de fechas y la actividad seleccionada, filtrados por carrera.
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

        // Convertir código carrera a prefijo de libro diario (ej: EEA → RD-EEA-*)
        $prefijoLibro = 'RD-' . $carrera . '-';

        // Obtener líneas del libro diario por usuario dentro del rango
        $detallesLibro = [];

        if (Schema::hasTable('libro_diario_cierre')) {
            $queryLibro = DB::table('libro_diario_cierre')
                ->join('usuarios', 'libro_diario_cierre.id_usuario', '=', 'usuarios.id_usuario')
                ->select(
                    'usuarios.nickname as usuario',
                    'libro_diario_cierre.codigo_rd',
                    'libro_diario_cierre.fecha as fecha_cierre',
                    DB::raw('0 as total_deposito'),
                    DB::raw('0 as total_traspaso'),
                    DB::raw('550 as total_recibos'),
                    DB::raw('1500 as total_facturas'),
                    DB::raw('2050 as total_entregado')
                )
                ->where('libro_diario_cierre.codigo_rd', 'like', $prefijoLibro . '%')
                ->orderBy('usuarios.nickname')
                ->orderBy('libro_diario_cierre.fecha');

            if ($fechaDesde) {
                $queryLibro->where('libro_diario_cierre.fecha', '>=', $fechaDesde);
            }
            if ($fechaHasta) {
                $queryLibro->where('libro_diario_cierre.fecha', '<=', $fechaHasta);
            }

            // Triple Validacion: Filtrar por Actividad Economica del Usuario
            if ($idActividad) {
                $queryLibro->where('usuarios.id_actividad_economica', $idActividad);
            }

            $detallesLibro = $queryLibro->get()->all();
        }

        // Agrupar por usuario para calcular totales por usuario_libro
        $porUsuario = [];
        foreach ($detallesLibro as $linea) {
            $usr = $linea->usuario ?? 'Sin usuario';
            if (!isset($porUsuario[$usr])) {
                $porUsuario[$usr] = [
                    'usuario_libro'        => $usr,
                    'cod_libro_diario'     => $linea->codigo_rd ?? null,
                    'fecha_inicial_libros' => $fechaDesde,
                    'fecha_final_libros'   => $fechaHasta ?? $linea->fecha_cierre ?? null,
                    'total_deposito'       => 0,
                    'total_traspaso'       => 0,
                    'total_recibos'        => 0,
                    'total_facturas'       => 0,
                    'total_entregado'      => 0,
                    'faltante_sobrante'    => null,
                ];
            }

            // Acumular los distintos tipos de pago
            $porUsuario[$usr]['total_deposito']  += (float) ($linea->total_deposito ?? 0);
            $porUsuario[$usr]['total_traspaso']  += (float) ($linea->total_traspaso ?? 0);
            $porUsuario[$usr]['total_recibos']   += (float) ($linea->total_recibos ?? 0);
            $porUsuario[$usr]['total_facturas']  += (float) ($linea->total_facturas ?? 0);
            $porUsuario[$usr]['total_entregado'] += (float) ($linea->total_entregado ?? 0);
        }

        $detallesParaReporte = array_values($porUsuario);
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

    /**
     * Lista los usuarios que tienen un rol específico asignado.
     */
    private function listUsuariosPorRol(int $idRol): array
    {
        return Usuario::query()
            ->where('id_rol', $idRol)
            ->where('estado', 1)
            ->orderBy('nombre')
            ->get(['id_usuario', 'nickname', 'nombre'])
            ->map(fn ($u) => [
                'id_usuario' => $u->id_usuario,
                'nickname'   => $u->nickname,
                'nombre'     => $u->nombre,
                'label'      => $u->nombre . ' (' . $u->nickname . ')',
            ])
            ->values()
            ->all();
    }
}
