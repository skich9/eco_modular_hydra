<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Funciones puras de mapeo para la migración histórica sistemaEco → SGA.
 *
 * Copiadas (NO acopladas) desde App\Services\Sga\SgaPushService, que es el camino
 * de sincronización en vivo del dev líder. Esta migración batch es una vía paralela
 * por escritura directa; mantenemos copias para no chocar con su rama.
 *
 * Las funciones que leen "qr_*", "nota_bancaria", "cuentas_bancarias", "asignacion_costos"
 * consultan sistemaEco (conexión MySQL por defecto). getNextNumPago consulta el SGA.
 */
class MapperHelper
{
    /** Conexión de SOLO LECTURA al backup de producción de sistemaEco (origen). */
    public const SOURCE_CONN = 'eco_backup';

    /** Query builder sobre el origen (backup). Toda lectura de sistemaEco pasa por acá. */
    private function src(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection(self::SOURCE_CONN);
    }

    /** Enruta por cod_pensum: EEA* -> sga_elec ; 04-MTZ* -> sga_mec */
    public function resolveConnectionByPensum(?string $codPensum): ?string
    {
        $p = strtoupper(trim((string) $codPensum));
        if ($p === '') return null;
        if (str_starts_with($p, 'EEA')) return 'sga_elec';
        if (str_starts_with($p, '04-MTZ')) return 'sga_mec';
        return null;
    }

    /** Enruta por prefijo de carrera (notas): E -> sga_elec ; M -> sga_mec */
    public function resolveConnectionByPrefijo(?string $prefijo): ?string
    {
        $p = strtoupper(trim((string) $prefijo));
        if ($p === 'E') return 'sga_elec';
        if ($p === 'M') return 'sga_mec';
        return null;
    }

    /** Copiado de SgaPushService: enruta por nombre de carrera. */
    public function resolveConnection(?string $carrera): ?string
    {
        if (!$carrera) return null;
        $lower = strtolower($carrera);
        if (str_contains($lower, 'mecánica') || str_contains($lower, 'mecanica')) {
            return 'sga_mec';
        }
        return 'sga_elec';
    }

    /** id_forma_cobro (sistemaEco) -> code_tipo_pago (SGA). Directo, default 'E'. */
    public function mapFormaCobro(?string $id): string
    {
        return $id ?: 'E';
    }

    /**
     * id_forma_cobro → codigo_sin de sin_forma_cobro (entero SIN/SGA).
     * Consulta la tabla sin_forma_cobro en eco_backup (misma BD que el origen).
     * Patrón idéntico a FacturaPayloadBuilder::buildXmlSectorEducativo().
     * Caché estático: como mucho 6-7 consultas por corrida completa.
     *
     * Fallback por código conocido ausente del seeder:
     *   T (Traspaso) → 999  (tarjeta débito/crédito, código SIN propio)
     * Fallback final: 1 (EFECTIVO) para cualquier código desconocido.
     */
    public function mapMetodoPago(?string $id): int
    {
        // Códigos conocidos ausentes del seeder de sin_forma_cobro
        static $fallback = ['T' => 999];
        static $cache    = [];

        $key = strtoupper(trim((string) $id));
        if (!array_key_exists($key, $cache)) {
            $codigoSin = $this->src()->table('sin_forma_cobro')
                ->where('id_forma_cobro', $key)
                ->orderBy('codigo_sin')
                ->value('codigo_sin');
            $cache[$key] = $codigoSin !== null
                ? (int) $codigoSin
                : ($fallback[$key] ?? 1);
        }
        return $cache[$key];
    }

    /**
     * tipo_documento (sistemaEco) -> tipo_documento_derivado (SGA).
     * 1→1 (CI), 2→2 (Pasaporte), 3→3 (Cédula), default 1.
     */
    public function mapTipoDocumento(?string $id): int
    {
        return match ((int) ($id ?? 1)) {
            1, 2, 3 => (int) $id,
            default => 1,
        };
    }

    /**
     * codigo_excepcion (sistemaEco) -> codigo_excepcion (SGA). Pass-through.
     */
    public function mapCodigoExcepcion(?string $codigo): ?string
    {
        return $codigo;
    }

    /**
     * codigo_doc_sector (sistemaEco) -> codigo_doc_sector (SGA). Pass-through.
     */
    public function mapCodigoDocSector(?string $codigo): ?string
    {
        return $codigo;
    }

    /**
     * tipo_emision (sistemaEco) -> tipo_emision (SGA).
     * 1→1 (Normal), 2→2 (Ajuste), default 1.
     */
    public function mapTipoEmision(?string $id): int
    {
        return match ((int) ($id ?? 1)) {
            1, 2 => (int) $id,
            default => 1,
        };
    }

    /**
     * num_cuota del cobro. Prioriza asignacion_costos.numero_cuota; fallback id_cuota/order/1.
     * Copiado de SgaPushService::resolveNumCuota.
     */
    public function resolveNumCuota(object $cobro): int
    {
        if (!empty($cobro->id_asignacion_costo)) {
            $cuota = $this->src()->table('asignacion_costos')
                ->where('id_asignacion_costo', $cobro->id_asignacion_costo)
                ->value('numero_cuota');
            if ($cuota !== null) {
                return (int) $cuota;
            }
        }
        return (int) (($cobro->id_cuota ?? 0) ?: ($cobro->order ?? 0) ?: 1);
    }

    /** Siguiente num_pago = MAX(col)+1 en el SGA para la clave dada. */
    public function getNextNumPago(string $conn, string $table, array $where, string $column = 'num_pago'): int
    {
        $max = DB::connection($conn)->table($table)->where($where)->max($column);
        return (int) $max + 1;
    }

    /** nota_bancaria de sistemaEco asociada al cobro (por nro_recibo/nro_factura). */
    public function getNotaBancaria(object $cobro): ?object
    {
        return $this->src()->table('nota_bancaria')
            ->where(function ($q) use ($cobro) {
                if (!empty($cobro->nro_recibo))  $q->where('nro_recibo', $cobro->nro_recibo);
                if (!empty($cobro->nro_factura)) $q->orWhere('nro_factura', $cobro->nro_factura);
            })
            ->first();
    }

    /** cuenta bancaria de sistemaEco por id_cuentas_bancarias. */
    public function getCuentaBancaria(object $cobro): ?object
    {
        if (empty($cobro->id_cuentas_bancarias)) {
            return null;
        }
        return $this->src()->table('cuentas_bancarias')
            ->where('id_cuentas_bancarias', $cobro->id_cuentas_bancarias)
            ->first();
    }

    /** ¿El cobro es por QR? (alias presente u observaciones con marca [QR]). */
    public function isQrPayment(object $cobro): bool
    {
        if (!empty($cobro->qr_alias)) return true;
        if (!empty($cobro->observaciones) && str_contains($cobro->observaciones, '[QR]')) return true;
        return false;
    }

    /** Busca la transacción QR por alias / observaciones / nro_factura / nro_recibo. */
    public function getQrTransaccion(object $cobro): ?object
    {
        if (!empty($cobro->qr_alias)) {
            $r = $this->src()->table('qr_transacciones')->where('alias', $cobro->qr_alias)->first();
            if ($r) return $r;
        }
        if (!empty($cobro->observaciones) && preg_match('/\[QR\]\s+alias:(\S+)/', $cobro->observaciones, $m)) {
            $r = $this->src()->table('qr_transacciones')->where('alias', $m[1])->first();
            if ($r) return $r;
        }
        if (!empty($cobro->nro_factura)) {
            $r = $this->src()->table('qr_transacciones')->where('nro_factura', $cobro->nro_factura)->where('cod_ceta', $cobro->cod_ceta)->first();
            if ($r) return $r;
        }
        if (!empty($cobro->nro_recibo)) {
            $r = $this->src()->table('qr_transacciones')->where('nro_recibo', $cobro->nro_recibo)->where('cod_ceta', $cobro->cod_ceta)->first();
            if ($r) return $r;
        }
        return null;
    }

    /** Última respuesta del banco para una transacción QR. */
    public function getQrRespuestaBanco(object $qrTransaccion): ?object
    {
        return $this->src()->table('qr_respuestas_banco')
            ->where('id_qr_transaccion', $qrTransaccion->id_qr_transaccion)
            ->orderByDesc('id_respuesta_banco')
            ->first();
    }

    /**
     * Resuelve el nombre de usuario del SGA (campo `usuario`) a partir del id_usuario
     * de sistemaEco (usuarios.nickname). Fallback 'SIS_ECO'.
     */
    public function resolveUsuarioNickname($idUsuario): string
    {
        if (!$idUsuario) return 'SIS_ECO';
        $nickname = $this->src()->table('usuarios')->where('id_usuario', $idUsuario)->value('nickname');
        return $nickname ? mb_substr($nickname, 0, 200) : 'SIS_ECO';
    }

    /**
     * cod_pensum del estudiante a partir de un documento (factura/recibo),
     * usando los cobros que lo referencian. Fallback secundario; preferir pensumFromCodCeta.
     */
    public function pensumFromDocumento(?int $nroFactura, ?int $nroRecibo): ?string
    {
        $q = $this->src()->table('cobro');
        if ($nroFactura) {
            $q->where('nro_factura', $nroFactura);
        } elseif ($nroRecibo) {
            $q->where('nro_recibo', $nroRecibo);
        } else {
            return null;
        }
        return $q->value('cod_pensum');
    }

    /**
     * cod_pensum del estudiante por cod_ceta (vía inscripciones). Ruteo principal de
     * factura/recibo/otros_ingresos, que tienen cod_ceta propio. Toma la inscripción más reciente.
     */
    public function pensumFromCodCeta($codCeta): ?string
    {
        if (!$codCeta) return null;
        return $this->src()->table('inscripciones')
            ->where('cod_ceta', $codCeta)
            ->orderByDesc('cod_inscrip')
            ->value('cod_pensum');
    }

    /** Conexión SGA directamente desde cod_ceta. */
    public function resolveConnByCodCeta($codCeta): ?string
    {
        return $this->resolveConnectionByPensum($this->pensumFromCodCeta($codCeta));
    }

    /**
     * Resuelve el cod_inscrip del SGA (source_cod_inscrip) a partir del cod_inscrip
     * interno de sistemaEco (PK auto_increment de la tabla inscripciones).
     *
     * IMPORTANTE: cobro.cod_inscrip es la PK interna de sistemaEco, NO el cod_inscrip
     * del SGA. El campo correcto para el SGA es inscripciones.source_cod_inscrip.
     *
     * Cachea en memoria para no repetir la consulta por cada cobro del mismo alumno.
     *
     * @return int|null  null si no existe la inscripción en sistemaEco.
     */
    public function resolveSourceCodInscrip(int $ecoCodInscrip): ?int
    {
        static $cache = [];
        if (array_key_exists($ecoCodInscrip, $cache)) {
            return $cache[$ecoCodInscrip];
        }
        $value = $this->src()->table('inscripciones')
            ->where('cod_inscrip', $ecoCodInscrip)
            ->value('source_cod_inscrip');
        $cache[$ecoCodInscrip] = $value !== null ? (int) $value : null;
        return $cache[$ecoCodInscrip];
    }

    /**
     * Resuelve cliente y nro_documento_cobro del documento asociado al cobro.
     * Prioridad: factura (si nro_factura no es null) → recibo (si nro_recibo no es null).
     * Usa anio_cobro para el JOIN correcto en el PK compuesto de factura/recibo.
     *
     * @return array{cliente: string|null, nro_documento_cobro: string|null}
     */
    /**
     * Construye el texto de observaciones para pago/pago_multa combinando
     * tipo de pago, observación original del cobro y datos bancarios.
     *
     * Orden: "{Tipo}: {obs_original} {banco}-{nro_deposito}-{fecha_deposito}"
     *
     * Efectivo:      "Efectivo: {obs}"  |  "Efectivo" si no hay obs
     * Transferencia: "Transferencia: [{obs}] {banco}-{nro}-{fecha}"
     * Tarjeta (L):   "Tarjeta: [{obs}] {banco}-{nro}-{fecha}"
     * Deposito (D):  "Deposito: [{obs}] {banco}-{nro}-{fecha}"
     * QR (B+QR):     "Transferencia: [{obs}] {nro}-{fecha}" (banco null en QR)
     *
     * Para D: usa $nota->banco_origen para el texto aunque banking['banco_origen'] sea null.
     * T es un tipo de pago distinto (no es Tarjeta).
     */
    public function resolveObservacionesPago(
        object  $cobro,
        array   $banking,
        ?object $nota,
        bool    $esQr
    ): ?string {
        $tipo    = strtoupper(trim($cobro->id_forma_cobro ?? ''));
        $obsOrig = trim($cobro->observaciones ?? '');

        // Efectivo: sin datos bancarios
        if ($tipo === 'E') {
            return $obsOrig !== '' ? "Efectivo: {$obsOrig}" : 'Efectivo';
        }

        // Prefijo según tipo — T es un tipo diferente, NO es Tarjeta
        $prefix = match ($tipo) {
            'B'     => 'Transferencia',
            'L'     => 'Tarjeta',
            'D'     => 'Deposito',
            'C'     => 'Cheque',
            default => $tipo !== '' ? $tipo : null,
        };

        if ($prefix === null) {
            return $obsOrig !== '' ? $obsOrig : null;
        }

        // Para QR banco es null; para D se toma $nota->banco_origen para la descripción
        $banco = $esQr
            ? null
            : (trim($nota->banco_origen ?? '') ?: null);

        $nro   = $banking['nro_deposito'];
        $fecha = $banking['fecha_deposito'];

        // Segmento banco-nro-fecha (solo partes no vacías)
        $partesBanc = array_filter([$banco, $nro, $fecha], fn($v) => $v !== null && $v !== '');
        $bankInfo   = !empty($partesBanc) ? implode('-', array_values($partesBanc)) : null;

        // Orden: tipo → obs original → datos bancarios
        $partesFinal = [];
        if ($obsOrig !== '')    $partesFinal[] = $obsOrig;
        if ($bankInfo !== null) $partesFinal[] = $bankInfo;

        $suffix = implode(' ', $partesFinal);
        return $suffix !== '' ? "{$prefix}: {$suffix}" : $prefix;
    }

    public function resolveClienteDoc(object $cobro): array
    {
        $empty = ['cliente' => null, 'nro_documento_cobro' => null];

        if (!empty($cobro->nro_factura)) {
            $row = $this->src()->table('factura')
                ->where('nro_factura', $cobro->nro_factura)
                ->where('anio', $cobro->anio_cobro)
                ->select('cliente', 'nro_documento_cobro')
                ->first();
            if ($row) {
                return ['cliente' => $row->cliente ?: null, 'nro_documento_cobro' => $row->nro_documento_cobro ?: null];
            }
        }

        if (!empty($cobro->nro_recibo)) {
            $row = $this->src()->table('recibo')
                ->where('nro_recibo', $cobro->nro_recibo)
                ->where('anio', $cobro->anio_cobro)
                ->select('cliente', 'nro_documento_cobro')
                ->first();
            if ($row) {
                return ['cliente' => $row->cliente ?: null, 'nro_documento_cobro' => $row->nro_documento_cobro ?: null];
            }
        }

        return $empty;
    }
}
