<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\QrTransaction;
use App\Services\Qr\QrGatewayService;
use App\Services\Qr\QrSocketNotifier;
use App\Http\Controllers\Api\CobroController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class QrController extends Controller
{
    public function initiate(Request $request, QrGatewayService $gateway)
    {
        $validated = $request->validate([
            'cod_ceta' => 'required|integer',
            'cod_pensum' => 'required|string',
            'tipo_inscripcion' => 'required|string',
            'id_usuario' => 'required|integer',
            'id_cuentas_bancarias' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'detalle' => 'required|string',
            'moneda' => 'required|string|in:BOB,USD',
            'items' => 'nullable|array',
        ]);

        $alias = $validated['cod_ceta'] . date('HisdmY');
        $callback = rtrim((string)config('qr.callback_base'), '/') . '/api/qr/callback';
        $formaCobro = (string)config('qr.forma_cobro_id', 'B');

        // Generar PK local usando doc_counter (scope: QR_TRANSACCION)
        DB::statement(
            "INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
            . "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
            ['QR_TRANSACCION']
        );
        $rowDoc = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
        $idQr = (int)($rowDoc->id ?? 0);

        // Expiración diaria a las 23:59:59 del día actual
        $fechaExpiracion = date('Y-m-d 23:59:59');
        $fechaVencimientoSip = date('d/m/Y');

        // Crear transacción base (estado generado)
        DB::table('qr_transacciones')->insert([
            'id_qr_transaccion' => $idQr,
            'id_usuario' => (int)$validated['id_usuario'],
            'id_cuenta_bancaria' => (string)$validated['id_cuentas_bancarias'],
            'alias' => $alias,
            'codigo_qr' => $idQr, // se actualiza con id externo si aplica
            'cod_ceta' => (int)$validated['cod_ceta'],
            'cod_pensum' => (string)$validated['cod_pensum'],
            'tipo_inscripcion' => (string)$validated['tipo_inscripcion'],
            'id_cuota' => null,
            'id_forma_cobro' => $formaCobro,
            'monto_total' => (float)$validated['amount'],
            'moneda' => null,
            'estado' => 'generado',
            'detalle_glosa' => (string)$validated['detalle'],
            'fecha_generacion' => now(),
            'fecha_expiracion' => $fechaExpiracion,
            'nro_autorizacion' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Persistir detalle (si se envía)
        $items = $validated['items'] ?? [];
        foreach ($items as $idx => $it) {
            $monto = (float)($it['monto'] ?? 0);
            $concepto = (string)($it['concepto'] ?? ($validated['detalle'] . ' #' . ($idx+1)));
            DB::table('qr_conceptos_detalle')->insert([
                'id_qr_transaccion' => $idQr,
                'tipo_concepto' => (string)($it['tipo_concepto'] ?? 'GENERAL'),
                'nro_cobro' => $it['nro_cobro'] ?? null,
                'concepto' => $concepto,
                'observaciones' => $it['observaciones'] ?? null,
                'precio_unitario' => $monto,
                'subtotal' => $monto,
                'orden' => (int)($it['order'] ?? ($idx+1)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Log de estado inicial
        DB::table('qr_estados_log')->insert([
            'id_qr_transaccion' => $idQr,
            'estado_anterior' => null,
            'estado_nuevo' => 'generado',
            'motivo_cambio' => 'init',
            'usuario' => (string)$validated['id_usuario'],
            'fecha_cambio' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Llamar proveedor para generar QR
        $auth = $gateway->authenticate();
        if (!$auth['ok']) {
            return response()->json(['success' => false, 'message' => 'QR auth failed'], 500);
        }
        $provReq = [
            'alias' => $alias,
            'callback' => $callback,
            'detalleGlosa' => $validated['detalle'],
            'monto' => (float)$validated['amount'],
            'moneda' => (string)$validated['moneda'],
            'fechaVencimiento' => $fechaVencimientoSip,
            'tipoSolicitud' => 'API',
            'unicoUso' => 'true',
        ];
        $resp = $gateway->createPayment($auth['token'], $provReq);
        if (!$resp['ok']) {
            Log::warning('QR createPayment failed', $resp);
            return response()->json(['success' => false, 'message' => 'QR provider error', 'meta' => $resp], 502);
        }
        $data = $resp['data'] ?? [];
        $codigo = $data['codigo'] ?? null;
        if (!in_array($codigo, ['0000', 'OK'], true)) {
            Log::warning('QR provider returned non-success code', ['codigo' => $codigo, 'data' => $data]);
            return response()->json(['success' => false, 'message' => 'QR provider non-success', 'meta' => $data], 502);
        }
        $qrBase64 = $data['objeto']['imagenQr'] ?? null;
        $external = $data['objeto']['idTransaccion'] ?? null;
        $idQr = $data['objeto']['idQr'] ?? null;
        $fechaVencResp = $data['objeto']['fechaVencimiento'] ?? null;
        $qrUrl = $data['objeto']['url'] ?? null; // si existiera
        $nroAut = $data['objeto']['numeroAutorizacion'] ?? null;

        DB::table('qr_transacciones')
            ->where('id_qr_transaccion', $idQr)
            ->update([
                'codigo_qr' => $external ? (int)$external : (is_numeric($idQr) ? (int)$idQr : $idQr),
                'nro_autorizacion' => $nroAut,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id_qr_transaccion' => $idQr,
                'alias' => $alias,
                'qr_image_base64' => $qrBase64,
                'qr_url' => $qrUrl,
                'external_id' => $external,
                'amount' => (float)$validated['amount'],
                'expires_at' => $fechaExpiracion,
            ],
        ]);
    }

    public function callback(Request $request, QrSocketNotifier $notifier)
    {
        // Basic Auth del callback (manual SIP). Si no hay credenciales configuradas, no se exige.
        $cbUser = (string)config('qr.callback_basic_user');
        $cbPass = (string)config('qr.callback_basic_pass');
        if ($cbUser !== '' || $cbPass !== '') {
            $auth = (string)$request->header('Authorization', '');
            if (stripos($auth, 'Basic ') !== 0) {
                return response()->json(['codigo' => '9999', 'mensaje' => 'Unauthorized'], 401);
            }
            $raw = base64_decode(substr($auth, 6) ?: '', true) ?: '';
            $pair = explode(':', $raw, 2);
            $u = $pair[0] ?? '';
            $p = $pair[1] ?? '';
            if (!hash_equals($cbUser, $u) || !hash_equals($cbPass, $p)) {
                return response()->json(['codigo' => '9999', 'mensaje' => 'Unauthorized'], 401);
            }
        }

        $alias = trim((string)$request->input('alias'));
        if ($alias === '') {
            $allowFallback = (bool)config('qr.allow_alias_fallback', app()->environment('local') || app()->environment('development'));
            if ($allowFallback) {
                $codCetaFallback = $request->input('cod_ceta');
                if ($codCetaFallback) {
                    $rowAlias = DB::table('qr_transacciones')
                        ->where('cod_ceta', (int)$codCetaFallback)
                        ->orderByDesc('created_at')
                        ->first();
                    if ($rowAlias) { $alias = (string)$rowAlias->alias; }
                }
            }
            if ($alias === '') {
                return response()->json(['codigo' => '9999', 'mensaje' => 'alias requerido'], 400);
            }
        }

        $trx = DB::table('qr_transacciones')->where('alias', $alias)->first();
        if (!$trx) {
            return response()->json(['codigo' => '9999', 'mensaje' => 'Transaction not found'], 404);
        }

        // Si llega campo 'estado' del flujo anterior, respetamos el mapeo; de lo contrario, es confirmación de pago.
        $statusExt = strtoupper((string)$request->input('estado', 'PAGADO'));
        $extId = (string)$request->input('idTransaccion', '');
        $formaCobro = (string)config('qr.forma_cobro_id', 'B');

        $estadoAnterior = (string)$trx->estado;
        $map = [
            'PAGADO' => 'completado',
            'PROCESANDO' => 'procesando',
            'PENDIENTE' => 'procesando',
            'CANCELADO' => 'cancelado',
            'EXPIRADO' => 'expirado',
        ];
        $estadoNuevo = $map[$statusExt] ?? 'completado';

        // Guardar traza de callback en respuestas_banco (lo que venga del manual)
        DB::table('qr_respuestas_banco')->insert([
            'id_qr_transaccion' => $trx->id_qr_transaccion,
            'banco' => 'SIP',
            'codigo_respuesta' => '0000',
            'mensaje_respuesta' => 'callback',
            'numero_autorizacion' => null,
            'numero_referencia' => (string)$request->input('numeroOrdenOriginante', ''),
            'numero_comprobante' => (string)$request->input('idQr', ''),
            'fecha_respuesta' => now(),
        ]);

        // Actualizar estado + id externo si llegó
        DB::table('qr_transacciones')
            ->where('id_qr_transaccion', $trx->id_qr_transaccion)
            ->update([
                'estado' => $estadoNuevo,
                'codigo_qr' => $extId ? (int)$extId : $trx->codigo_qr,
                'updated_at' => now(),
            ]);

        // Contar COMPLETADO previo ANTES de insertar el log actual (para idempotencia)
        $prevCompletadoCount = DB::table('qr_estados_log')
            ->where('id_qr_transaccion', $trx->id_qr_transaccion)
            ->where('estado_nuevo', 'completado')
            ->count();

        DB::table('qr_estados_log')->insert([
            'id_qr_transaccion' => $trx->id_qr_transaccion,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'motivo_cambio' => 'callback',
            'usuario' => null,
            'fecha_cambio' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($estadoNuevo === 'completado') {
            try {
                $conceptos = DB::table('qr_conceptos_detalle')
                    ->where('id_qr_transaccion', $trx->id_qr_transaccion)
                    ->orderBy('orden')
                    ->get();
                $items = [];
                foreach ($conceptos as $c) {
                    $items[] = [
                        'monto' => (float)$c->subtotal,
                        'fecha_cobro' => date('Y-m-d'),
                        'order' => (int)$c->orden,
                        'observaciones' => (string)$c->observaciones,
                        'nro_cobro' => $c->nro_cobro,
                        'pu_mensualidad' => isset($c->precio_unitario) ? (float)$c->precio_unitario : (float)$c->subtotal,
                        'id_forma_cobro' => $formaCobro,
                    ];
                }
                if (empty($items)) {
                    $items[] = [
                        'monto' => (float)$trx->monto_total,
                        'fecha_cobro' => date('Y-m-d'),
                        'order' => 1,
                        'observaciones' => (string)$trx->detalle_glosa,
                        'pu_mensualidad' => (float)$trx->monto_total,
                        'id_forma_cobro' => $formaCobro,
                    ];
                }

                // Incluir cuenta bancaria solo si existe realmente (evita fallo de validación exists)
                $maybeAccountId = (int)($trx->id_cuenta_bancaria ?? 0);
                $accountExists = $maybeAccountId > 0 && DB::table('cuentas_bancarias')
                    ->where('id_cuentas_bancarias', $maybeAccountId)
                    ->exists();

                // Verificar si ya existen cobros hoy para este estudiante con la forma QR (para permitir reintento si el 1er intento falló)
                $hasCobros = DB::table('cobro')
                    ->where('cod_ceta', (int)$trx->cod_ceta)
                    ->where('id_forma_cobro', $formaCobro)
                    ->whereDate('fecha_cobro', date('Y-m-d'))
                    ->exists();

                $payload = [
                    'cod_ceta' => (int)$trx->cod_ceta,
                    'cod_pensum' => (string)$trx->cod_pensum,
                    'tipo_inscripcion' => (string)$trx->tipo_inscripcion,
                    'gestion' => null,
                    'id_usuario' => (int)$trx->id_usuario,
                    'id_forma_cobro' => $formaCobro,
                    'items' => $items,
                ];
                if ($accountExists) {
                    $payload['id_cuentas_bancarias'] = $maybeAccountId;
                }
                if ($prevCompletadoCount === 0 || !$hasCobros) {
                    $req = Request::create('/api/cobros/batch', 'POST', $payload);
                    $controller = app(CobroController::class);
                    $resp = app()->call([$controller, 'batchStore'], ['request' => $req]);
                    if ($resp instanceof \Illuminate\Http\JsonResponse) {
                        $body = $resp->getData(true);
                        if (!($body['success'] ?? false)) {
                            Log::warning('QR batchStore validation failed', [
                                'alias' => $alias,
                                'errors' => $body['errors'] ?? $body,
                            ]);
                        }
                    }
                } else {
                    Log::info('QR batchStore skipped (idempotent)', [
                        'alias' => $alias,
                        'prevCompletadoCount' => $prevCompletadoCount,
                        'hasCobros' => $hasCobros,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('QR callback batch process failed', ['alias' => $alias, 'err' => $e->getMessage()]);
            }
        }

        $notifier->notify(null, [
            'alias' => $alias,
            'status' => $estadoNuevo,
            'cod_ceta' => $trx->cod_ceta,
            'amount' => $trx->monto_total,
        ]);

        return response()->json(['codigo' => '0000', 'mensaje' => 'Registro exitoso']);
    }

    public function disable(Request $request, QrGatewayService $gateway)
    {
        $alias = (string)$request->input('alias');
        if (!$alias) { return response()->json(['success' => false, 'message' => 'alias requerido'], 422); }

        $trx = DB::table('qr_transacciones')->where('alias', $alias)->first();
        if (!$trx) { return response()->json(['success' => false, 'message' => 'Transaction not found'], 404); }

        $auth = $gateway->authenticate();
        if (!$auth['ok']) { return response()->json(['success' => false, 'message' => 'QR auth failed'], 500); }

        $resp = $gateway->disablePayment($auth['token'], $alias);
        if (!$resp['ok']) {
            Log::warning('QR disablePayment failed', $resp);
            return response()->json(['success' => false, 'message' => 'QR provider error', 'meta' => $resp], 502);
        }

        // Mapear INHABILITADO -> cancelado (no existe "inhabilitado" en nuestro enum)
        $estadoNuevo = 'cancelado';
        $estadoAnterior = (string)$trx->estado;
        if ($estadoAnterior !== $estadoNuevo) {
            DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                'estado' => $estadoNuevo,
                'updated_at' => now(),
            ]);
            DB::table('qr_estados_log')->insert([
                'id_qr_transaccion' => $trx->id_qr_transaccion,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $estadoNuevo,
                'motivo_cambio' => 'inhabilitar',
                'usuario' => (string)($request->input('id_usuario') ?? ''),
                'fecha_cambio' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $resp['data']]);
    }

    public function status(Request $request, QrGatewayService $gateway)
    {
        $alias = (string)$request->input('alias');
        if (!$alias) { return response()->json(['success' => false, 'message' => 'alias requerido'], 422); }

        $trx = DB::table('qr_transacciones')->where('alias', $alias)->first();
        if (!$trx) { return response()->json(['success' => false, 'message' => 'Transaction not found'], 404); }

        $auth = $gateway->authenticate();
        if (!$auth['ok']) { return response()->json(['success' => false, 'message' => 'QR auth failed'], 500); }

        $resp = $gateway->getStatus($auth['token'], $alias);
        if (!$resp['ok']) {
            Log::warning('QR getStatus failed', $resp);
            return response()->json(['success' => false, 'message' => 'QR provider error', 'meta' => $resp], 502);
        }

        $payload = $resp['data'] ?? [];
        $estadoExt = strtoupper((string)($payload['objeto']['estadoActual'] ?? ''));
        $map = [
            'PAGADO' => 'completado',
            'INHABILITADO' => 'cancelado',
            'ERROR' => 'cancelado',
            'EXPIRADO' => 'expirado',
            'PENDIENTE' => 'procesando',
        ];
        $estadoNuevo = $map[$estadoExt] ?? (string)$trx->estado;

        if ($estadoNuevo !== (string)$trx->estado) {
            DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                'estado' => $estadoNuevo,
                'updated_at' => now(),
            ]);
            DB::table('qr_estados_log')->insert([
                'id_qr_transaccion' => $trx->id_qr_transaccion,
                'estado_anterior' => (string)$trx->estado,
                'estado_nuevo' => $estadoNuevo,
                'motivo_cambio' => 'consulta_estado',
                'usuario' => (string)($request->input('id_usuario') ?? ''),
                'fecha_cambio' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $payload]);
    }
}
