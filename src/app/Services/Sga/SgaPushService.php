<?php

namespace App\Services\Sga;

use App\Models\Cobro;
use App\Models\CuentaBancaria;
use App\Models\Inscripcion;
use App\Models\SgaPushCobro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SgaPushService
{
    private const LOG = 'SgaPushService';

    public function pushCobro(Cobro $cobro)
    {
        $ctx = ['cod_ceta' => $cobro->cod_ceta, 'nro_cobro' => $cobro->nro_cobro, 'anio_cobro' => $cobro->anio_cobro, 'cod_tipo_cobro' => $cobro->cod_tipo_cobro, 'cod_inscrip' => $cobro->cod_inscrip];

        Log::info(self::LOG . ': pushCobro iniciado', $ctx);

        try {
            $inscripcion = Inscripcion::where('cod_inscrip', $cobro->cod_inscrip)->first();
            if (!$inscripcion) {
                Log::warning(self::LOG . ': inscripción no encontrada', $ctx);
                return false;
            }

            Log::info(self::LOG . ': inscripción encontrada', ['cod_inscrip' => $inscripcion->cod_inscrip, 'source_cod_inscrip' => $inscripcion->source_cod_inscrip, 'carrera' => $inscripcion->carrera]);

            $connection = $this->resolveConnection($inscripcion->carrera);
            if (!$connection) {
                Log::warning(self::LOG . ': no se pudo determinar conexión SGA', ['carrera' => $inscripcion->carrera]);
                return false;
            }

            Log::info(self::LOG . ': conexión resuelta', ['conn' => $connection]);

            $tipo = strtoupper((string) $cobro->cod_tipo_cobro);

            if (in_array($tipo, ['MENSUALIDAD', 'ARRASTRE'])) {
                return $this->pushToPago($connection, $cobro, $inscripcion);
            } elseif (in_array($tipo, ['MORA', 'NIVELACION'])) {
                return $this->pushToPagoMulta($connection, $cobro, $inscripcion);
            } elseif ($tipo === 'REINCORPORACION') {
                return $this->pushToMatricula($connection, $cobro, $inscripcion);
            }

            Log::info(self::LOG . ': tipo de cobro no configurado para sync SGA', array_merge($ctx, ['tipo' => $tipo]));
            return false;

        } catch (\Throwable $e) {
            Log::error(self::LOG . ': excepción en pushCobro — ' . $e->getMessage(), array_merge($ctx, ['trace' => $e->getTraceAsString()]));
            return false;
        }
    }

    private function pushToPago(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $cobroUid = $cobro->anio_cobro . '-' . $cobro->nro_cobro;

        Log::info(self::LOG . ': pushToPago iniciado', ['cobro_uid' => $cobroUid, 'conn' => $conn]);

        $registro = SgaPushCobro::where('cobro_uid', $cobroUid)->first();
        if ($registro && $registro->sincronizado) {
            Log::info(self::LOG . ': cobro ya sincronizado, se omite', ['cobro_uid' => $cobroUid]);
            return true;
        }

        $numCuota = $this->resolveNumCuota($cobro);
        Log::info(self::LOG . ': num_cuota resuelto', ['cobro_uid' => $cobroUid, 'num_cuota' => $numCuota, 'id_asignacion_costo' => $cobro->id_asignacion_costo]);

        $payload = $this->buildPayloadPago($cobro, $ins, $numCuota);
        Log::info(self::LOG . ': payload construido', ['cobro_uid' => $cobroUid, 'payload' => $payload]);

        $registro = SgaPushCobro::updateOrCreate(
            ['cobro_uid' => $cobroUid],
            [
                'nro_cobro'     => $cobro->nro_cobro,
                'anio_cobro'    => $cobro->anio_cobro,
                'cod_ceta'      => $cobro->cod_ceta,
                'cod_pensum'    => $cobro->cod_pensum,
                'destino_conn'  => $conn,
                'destino_tabla' => 'pago',
                'payload'       => $payload,
                'sincronizado'  => false,
            ]
        );

        Log::info(self::LOG . ': registro sga_push_cobros guardado', ['id' => $registro->id, 'cobro_uid' => $cobroUid]);

        $pushEnabled = config('sga.push_enabled') ?? (env('SGA_PUSH_ENABLED', 'false') !== 'false' && env('SGA_PUSH_ENABLED', false));

        if (!$pushEnabled) {
            Log::info(self::LOG . ': push deshabilitado (SGA_PUSH_ENABLED=false), cobro guardado como pendiente', ['cobro_uid' => $cobroUid]);
            return true;
        }

        return $this->enviarAlSga($registro, $conn, '/api/sync/pago', $payload);
    }

    private function pushToPagoMulta(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $numCuota     = $this->resolveNumCuota($cobro);
        $detalleMulta = $cobro->detalleMulta;

        $nextNumPago = $this->getNextNumPago($conn, 'pago_multa', [
            'cod_ceta'        => $cobro->cod_ceta,
            'cod_pensum'      => $cobro->cod_pensum,
            'gestion'         => $cobro->gestion,
            'kardex_economico'=> $cobro->tipo_inscripcion,
            'num_cuota'       => $numCuota,
        ]);

        $data = $this->mapBaseFields($cobro, $ins);
        $data['gestion']    = $cobro->gestion;
        $data['num_cuota']  = $numCuota;
        $data['num_pago']   = $nextNumPago;
        $data['pu_multa']   = (float) ($detalleMulta->pu_multa ?? 0);
        $data['dias_multa'] = (int) ($detalleMulta->dias_multa ?? 0);
        $data['descuento']  = (float) ($cobro->descuento ?? 0);

        return DB::connection($conn)->table('pago_multa')->insert($data);
    }

    private function pushToMatricula(string $conn, Cobro $cobro, Inscripcion $ins)
    {
        $nextNumPago = $this->getNextNumPago($conn, 'matricula', [
            'cod_ceta'        => $cobro->cod_ceta,
            'cod_pensum'      => $cobro->cod_pensum,
            'cod_inscrip'     => $ins->source_cod_inscrip,
            'kardex_economico'=> $cobro->tipo_inscripcion,
        ], 'num_pago_matri');

        $data = $this->mapBaseFields($cobro, $ins);
        $data['cod_inscrip']    = $ins->source_cod_inscrip;
        $data['num_pago_matri'] = $nextNumPago;
        $data['costo']          = (float) $cobro->monto;
        $data['matriculatotal'] = (float) $cobro->monto;
        $data['descuento']      = (float) ($cobro->descuento ?? 0);

        return DB::connection($conn)->table('matricula')->insert($data);
    }

    public function enviarAlSga(SgaPushCobro $registro, string $conn, string $endpoint, array $payload): bool
    {
        [$url, $token] = $this->resolveApiCredentials($conn);

        Log::info(self::LOG . ': enviando al SGA', [
            'cobro_uid' => $registro->cobro_uid,
            'url'       => $url . $endpoint,
            'token_set' => !empty($token),
        ]);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post($url . $endpoint, $payload);

            $registro->increment('intentos');

            Log::info(self::LOG . ': respuesta SGA recibida', [
                'cobro_uid' => $registro->cobro_uid,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);

            if ($response->successful() && ($response->json('success') ?? false)) {
                $registro->update([
                    'sincronizado'   => true,
                    'response'       => $response->json(),
                    'ultimo_error'   => null,
                    'sincronizado_at'=> now(),
                ]);
                Log::info(self::LOG . ': sincronización exitosa', ['cobro_uid' => $registro->cobro_uid, 'num_pago' => $response->json('num_pago')]);
                return true;
            }

            $registro->update([
                'response'    => $response->json(),
                'ultimo_error'=> $response->json('message') ?? 'HTTP ' . $response->status(),
            ]);

            Log::warning(self::LOG . ': SGA rechazó el pago', [
                'cobro_uid' => $registro->cobro_uid,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);

            return false;

        } catch (\Throwable $e) {
            $registro->increment('intentos');
            $registro->update(['ultimo_error' => $e->getMessage()]);

            Log::error(self::LOG . ': error HTTP al enviar al SGA', [
                'cobro_uid' => $registro->cobro_uid,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildPayloadPago(Cobro $cobro, Inscripcion $ins, int $numCuota): array
    {
        // Cargar solo las relaciones que tienen un FK válido (> 0)
        // Si nro_recibo/nro_factura = 0, belongsTo devolvería un documento ajeno
        $toLoad = ['usuario'];
        if ($cobro->nro_recibo > 0)  $toLoad[] = 'recibo';
        if ($cobro->nro_factura > 0) $toLoad[] = 'factura';
        $cobro->load($toLoad);

        $documento = ($cobro->nro_recibo  > 0 ? $cobro->recibo  : null)
                  ?: ($cobro->nro_factura > 0 ? $cobro->factura : null);
        $usuario        = $cobro->usuario;
        $notaBancaria   = $this->getNotaBancaria($cobro);
        $cuentaBancaria = $this->getCuentaBancaria($cobro);

        return [
            'cod_ceta'          => $cobro->cod_ceta,
            'cod_pensum'        => $cobro->cod_pensum,
            'cod_inscrip'       => $ins->source_cod_inscrip,
            'kardex_economico'  => $cobro->tipo_inscripcion,
            'num_cuota'         => $numCuota,
            'pu_mensualidad'    => (float) ($cobro->pu_mensualidad ?? 0),
            'descuento'         => (float) ($cobro->descuento ?? 0),
            'monto'             => (float) $cobro->monto,
            'fecha_pago'        => $cobro->fecha_cobro
                                    ? \Carbon\Carbon::parse($cobro->fecha_cobro)->format('Y-m-d H:i:s')
                                    : null,
            'pago_completo'     => (bool) $cobro->cobro_completo,
            'num_comprobante'   => (int) ($cobro->nro_recibo ?? 0),
            'num_factura'       => (int) ($cobro->nro_factura ?? 0),
            'observaciones'     => $cobro->observaciones,
            'concepto'          => $cobro->concepto ?? 'Cobro SisEco',
            'usuario'           => $usuario ? $usuario->nickname : 'SIS_ECO',
            'razon'             => $documento ? mb_substr($documento->cliente, 0, 100) : null,
            'nro_documento_pago'=> $documento ? mb_substr($documento->nro_documento_cobro, 0, 50) : null,
            'code_tipo_pago'    => $this->mapFormaCobro($cobro->id_forma_cobro),
            'anulado'           => false,
            'destino_tabla'     => 'pago',
            'fecha_deposito'    => $notaBancaria ? $notaBancaria->fecha_deposito : null,
            'nro_deposito'      => $notaBancaria ? mb_substr($notaBancaria->nro_transaccion ?? '', 0, 35) : null,
            'banco_origen'      => null,
            'cuenta_bancaria'   => $cuentaBancaria ? [
                'numero_cuenta' => $cuentaBancaria->numero_cuenta,
                'banco'         => $cuentaBancaria->banco,
                'tipo_cuenta'   => $cuentaBancaria->tipo_cuenta,
                'titular'       => $cuentaBancaria->titular,
            ] : null,
        ];
    }

    private function mapBaseFields(Cobro $cobro, Inscripcion $ins): array
    {
        $documento    = $cobro->recibo ?: $cobro->factura;
        $usuario      = $cobro->usuario;
        $notaBancaria = $this->getNotaBancaria($cobro);

        return [
            'cod_ceta'          => $cobro->cod_ceta,
            'cod_pensum'        => $cobro->cod_pensum,
            'kardex_economico'  => $cobro->tipo_inscripcion,
            'monto'             => (float) $cobro->monto,
            'num_comprobante'   => (int) ($cobro->nro_recibo ?? 0),
            'num_factura'       => (int) ($cobro->nro_factura ?? 0),
            'fecha_pago'        => $cobro->fecha_cobro,
            'pago_completo'     => (bool) $cobro->cobro_completo,
            'observaciones'     => $cobro->observaciones,
            'usuario'           => $usuario ? $usuario->nickname : 'SIS_ECO',
            'razon'             => $documento ? mb_substr($documento->cliente, 0, 100) : null,
            'nro_documento_pago'=> $documento ? mb_substr($documento->nro_documento_cobro, 0, 50) : null,
            'concepto'          => $cobro->concepto ?? 'Cobro SisEco',
            'code_tipo_pago'    => $this->mapFormaCobro($cobro->id_forma_cobro),
            'anulado'           => false,
            'fecha_deposito'    => $notaBancaria ? $notaBancaria->fecha_deposito : null,
            'nro_cuenta'        => $notaBancaria ? mb_substr($notaBancaria->nro_cuenta ?? '', 0, 35) : null,
            'nro_deposito'      => $notaBancaria ? mb_substr($notaBancaria->nro_transaccion ?? '', 0, 35) : null,
            'banco_origen'      => $notaBancaria ? mb_substr($notaBancaria->banco ?? '', 0, 200) : null,
        ];
    }

    private function resolveNumCuota(Cobro $cobro): int
    {
        if ($cobro->id_asignacion_costo) {
            $cuota = DB::table('asignacion_costos')
                ->where('id_asignacion_costo', $cobro->id_asignacion_costo)
                ->value('numero_cuota');
            if ($cuota !== null) {
                return (int) $cuota;
            }
        }
        return (int) ($cobro->id_cuota ?: $cobro->order ?: 1);
    }

    private function resolveConnection(?string $carrera): ?string
    {
        if (!$carrera) return null;
        $lower = strtolower($carrera);
        if (str_contains($lower, 'mecánica') || str_contains($lower, 'mecanica')) {
            return 'sga_mec';
        }
        return 'sga_elec';
    }

    private function resolveApiCredentials(string $conn): array
    {
        if ($conn === 'sga_mec') {
            $url   = config('sga.mec_url') ?: env('SGA_MECANICA_URL') ?: env('SGA_BASE_URL', '');
            $token = config('sga.mec_token') ?: env('SGA_API_MECANICA_TOKEN') ?: env('SGA_API_TOKEN', '');
            return [rtrim($url, '/'), $token];
        }
        $url   = config('sga.elec_url') ?: env('SGA_BASE_URL', '');
        $token = config('sga.elec_token') ?: env('SGA_API_TOKEN', '');
        return [rtrim($url, '/'), $token];
    }

    private function getNextNumPago(string $conn, string $table, array $where, string $column = 'num_pago'): int
    {
        $max = DB::connection($conn)->table($table)->where($where)->max($column);
        return (int) $max + 1;
    }

    private function getNotaBancaria(Cobro $cobro)
    {
        return DB::table('nota_bancaria')
            ->where(function ($q) use ($cobro) {
                if ($cobro->nro_recibo)  $q->where('nro_recibo', $cobro->nro_recibo);
                if ($cobro->nro_factura) $q->orWhere('nro_factura', $cobro->nro_factura);
            })
            ->first();
    }

    private function getCuentaBancaria(Cobro $cobro): ?CuentaBancaria
    {
        if (!$cobro->id_cuentas_bancarias) {
            return null;
        }
        return CuentaBancaria::find($cobro->id_cuentas_bancarias);
    }

    private function mapFormaCobro(?string $id): string
    {
        return $id ?: 'E';
    }
}
