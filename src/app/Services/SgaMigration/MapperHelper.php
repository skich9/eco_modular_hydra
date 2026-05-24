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
     * id_forma_cobro -> codigo_metodo_pago (entero SIN/SGA).
     * E=1 efectivo, L=2 tarjeta, C=3 cheque, O=4 otros/vales, B=7 transferencia, D=8 depósito, T=1.
     */
    public function mapMetodoPago(?string $id): int
    {
        return match (strtoupper((string) $id)) {
            'L' => 2,
            'C' => 3,
            'O' => 4,
            'B' => 7,
            'D' => 8,
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
}
