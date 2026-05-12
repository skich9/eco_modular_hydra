<?php

namespace App\Services\Sga;

use App\Models\Cobro;
use App\Models\Inscripcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SgaPushService
{
    /**
     * Punto de entrada principal para sincronizar un cobro hacia el SGA.
     */
    public function pushCobro(Cobro $cobro)
    {
        try {
            // 1. Obtener la inscripción para conocer la carrera y el ID original del SGA
            $inscripcion = Inscripcion::where('cod_inscrip', $cobro->cod_inscrip)->first();
            if (!$inscripcion) {
                Log::warning('SgaPushService: No se encontró inscripción para el cobro', ['cod_ceta' => $cobro->cod_ceta, 'nro_cobro' => $cobro->nro_cobro]);
                return false;
            }

            // 2. Determinar la conexión (Electronica o Mecánica)
            $connection = $this->resolveConnection($inscripcion->carrera);
            if (!$connection) {
                Log::warning('SgaPushService: No se pudo determinar la conexión SGA para la carrera', ['carrera' => $inscripcion->carrera]);
                return false;
            }

            // 3. Enrutar según el tipo de cobro
            $tipo = strtoupper((string) $cobro->cod_tipo_cobro);

            if (in_array($tipo, ['MENSUALIDAD', 'ARRASTRE'])) {
                return $this->pushToPago($connection, $cobro, $inscripcion);
            } elseif (in_array($tipo, ['MORA', 'NIVELACION'])) {
                return $this->pushToPagoMulta($connection, $cobro, $inscripcion);
            } elseif ($tipo === 'REINCORPORACION') {
                return $this->pushToMatricula($connection, $cobro, $inscripcion);
            }

            Log::info('SgaPushService: Tipo de cobro no configurado para sincronización SGA', ['tipo' => $tipo]);
            return false;

        } catch (\Throwable $e) {
            Log::error('SgaPushService Error: ' . $e->getMessage(), [
                'nro_cobro' => $cobro->nro_cobro,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Inserta en la tabla 'pago' del SGA (Mensualidades/Arrastres).
     */
    private function pushToPago(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $numCuota = (int) ($cobro->id_cuota ?: $cobro->order ?: 1);
        
        // Calcular el siguiente num_pago para esta cuota en SGA
        $nextNumPago = $this->getNextNumPago($conn, 'pago', [
            'cod_ceta' => $cobro->cod_ceta,
            'cod_pensum' => $cobro->cod_pensum,
            'cod_inscrip' => $ins->source_cod_inscrip,
            'kardex_economico' => $cobro->tipo_inscripcion,
            'num_cuota' => $numCuota
        ]);

        $data = $this->mapBaseFields($cobro, $ins);
        $data['num_cuota'] = $numCuota;
        $data['num_pago'] = $nextNumPago;
        $data['cod_inscrip'] = $ins->source_cod_inscrip;
        $data['pu_mensualidad'] = (float) ($cobro->pu_mensualidad ?? 0);
        $data['descuento'] = (float) ($cobro->descuento ?? 0);

        return DB::connection($conn)->table('pago')->insert($data);
    }

    /**
     * Inserta en la tabla 'pago_multa' del SGA (Mora/Nivelación).
     */
    private function pushToPagoMulta(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $numCuota = (int) ($cobro->id_cuota ?: $cobro->order ?: 1);
        $detalleMulta = $cobro->detalleMulta;

        $nextNumPago = $this->getNextNumPago($conn, 'pago_multa', [
            'cod_ceta' => $cobro->cod_ceta,
            'cod_pensum' => $cobro->cod_pensum,
            'gestion' => $cobro->gestion,
            'kardex_economico' => $cobro->tipo_inscripcion,
            'num_cuota' => $numCuota
        ]);

        $data = $this->mapBaseFields($cobro, $ins);
        $data['gestion'] = $cobro->gestion;
        $data['num_cuota'] = $numCuota;
        $data['num_pago'] = $nextNumPago;
        $data['pu_multa'] = (float) ($detalleMulta->pu_multa ?? 0);
        $data['dias_multa'] = (int) ($detalleMulta->dias_multa ?? 0);
        $data['descuento'] = (float) ($cobro->descuento ?? 0);

        return DB::connection($conn)->table('pago_multa')->insert($data);
    }

    /**
     * Inserta en la tabla 'matricula' del SGA (Reincorporaciones).
     */
    private function pushToMatricula(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $nextNumPago = $this->getNextNumPago($conn, 'matricula', [
            'cod_ceta' => $cobro->cod_ceta,
            'cod_pensum' => $cobro->cod_pensum,
            'cod_inscrip' => $ins->source_cod_inscrip,
            'kardex_economico' => $cobro->tipo_inscripcion,
        ], 'num_pago_matri');

        $data = $this->mapBaseFields($cobro, $ins);
        $data['cod_inscrip'] = $ins->source_cod_inscrip;
        $data['num_pago_matri'] = $nextNumPago;
        $data['costo'] = (float) $cobro->monto;
        $data['matriculatotal'] = (float) $cobro->monto;
        $data['descuento'] = (float) ($cobro->descuento ?? 0);

        return DB::connection($conn)->table('matricula')->insert($data);
    }

    /**
     * Mapea los campos comunes a todas las tablas de cobro del SGA.
     */
    private function mapBaseFields(Cobro $cobro, Inscripcion $ins): array
    {
        $documento = $cobro->recibo ?: $cobro->factura;
        $usuario = $cobro->usuario;
        $notaBancaria = $this->getNotaBancaria($cobro);

        return [
            'cod_ceta' => $cobro->cod_ceta,
            'cod_pensum' => $cobro->cod_pensum,
            'kardex_economico' => $cobro->tipo_inscripcion,
            'monto' => (float) $cobro->monto,
            'num_comprobante' => (int) ($cobro->nro_recibo ?? 0),
            'num_factura' => (int) ($cobro->nro_factura ?? 0),
            'fecha_pago' => $cobro->fecha_cobro,
            'pago_completo' => (bool) $cobro->cobro_completo,
            'observaciones' => $cobro->observaciones,
            'usuario' => $usuario ? $usuario->nickname : 'SIS_ECO',
            'razon' => $documento ? mb_substr($documento->cliente, 0, 100) : null,
            'nro_documento_pago' => $documento ? mb_substr($documento->nro_documento_cobro, 0, 50) : null,
            'concepto' => $cobro->concepto ?? 'Cobro SisEco',
            'code_tipo_pago' => $this->mapFormaCobro($cobro->id_forma_cobro),
            'anulado' => false,
            
            // Datos bancarios
            'fecha_deposito' => $notaBancaria ? $notaBancaria->fecha_deposito : null,
            'nro_cuenta' => $notaBancaria ? mb_substr($notaBancaria->nro_cuenta, 0, 35) : null,
            'nro_deposito' => $notaBancaria ? mb_substr($notaBancaria->nro_transaccion, 0, 35) : null,
            'banco_origen' => $notaBancaria ? mb_substr($notaBancaria->banco, 0, 200) : null,
        ];
    }

    private function resolveConnection(?string $carrera): ?string
    {
        if (!$carrera) return null;
        if (str_contains(strtolower($carrera), 'mecánica') || str_contains(strtolower($carrera), 'mecanica')) {
            return 'sga_mec';
        }
        return 'sga_elec'; // Por defecto o para Electrónica
    }

    private function getNextNumPago(string $conn, string $table, array $where, string $column = 'num_pago'): int
    {
        $max = DB::connection($conn)->table($table)->where($where)->max($column);
        return (int) $max + 1;
    }

    private function getNotaBancaria(Cobro $cobro)
    {
        return DB::table('nota_bancaria')
            ->where(function($q) use ($cobro) {
                if ($cobro->nro_recibo) $q->where('nro_recibo', $cobro->nro_recibo);
                if ($cobro->nro_factura) $q->orWhere('nro_factura', $cobro->nro_factura);
            })
            ->first();
    }

    private function mapFormaCobro(?string $id): string
    {
        return $id ?: 'E';
    }
}
