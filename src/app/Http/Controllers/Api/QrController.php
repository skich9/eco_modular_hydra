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
    private function applyAccountOverrides(int $idCuenta): void
    {
        if ($idCuenta <= 0) return;
        try {
            $cta = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idCuenta)->first();
            if (!$cta) return;
            $set = function(string $key, $val): void { if ($val !== null && $val !== '') { config([$key => $val]); } };
            $set('qr.url_auth', $cta->qr_url_auth ?? null);
            $set('qr.api_key', $cta->qr_api_key ?? null);
            $set('qr.username', $cta->qr_username ?? null);
            $set('qr.password', $cta->qr_password ?? null);
            $set('qr.url_transfer', $cta->qr_url_transfer ?? null);
            $set('qr.api_key_servicio', $cta->qr_api_key_servicio ?? null);
            if (isset($cta->qr_http_verify_ssl)) { config(['qr.http_verify_ssl' => (bool)$cta->qr_http_verify_ssl]); }
            if (isset($cta->qr_http_timeout) && is_numeric($cta->qr_http_timeout)) { config(['qr.http_timeout' => (int)$cta->qr_http_timeout]); }
            if (isset($cta->qr_http_connect_timeout) && is_numeric($cta->qr_http_connect_timeout)) { config(['qr.http_connect_timeout' => (int)$cta->qr_http_connect_timeout]); }
            Log::info('QR overrides applied (by account)', [
                'id_cuentas_bancarias' => $idCuenta,
                'has_overrides' => !!(($cta->qr_url_auth ?? null) || ($cta->qr_api_key ?? null) || ($cta->qr_url_transfer ?? null) || ($cta->qr_api_key_servicio ?? null))
            ]);
        } catch (\Throwable $e) {
            Log::warning('applyAccountOverrides error', ['err' => $e->getMessage(), 'id_cuentas_bancarias' => $idCuenta]);
        }
    }
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
            'gestion' => 'nullable|string',
        ]);

        // Overrides de configuración por cuenta bancaria (si aplica) y validación de habilitado_QR
        try {
            $cta = \Illuminate\Support\Facades\DB::table('cuentas_bancarias')
                ->where('id_cuentas_bancarias', (int)$validated['id_cuentas_bancarias'])
                ->first();
            if (!$cta) {
                return response()->json(['success' => false, 'message' => 'Cuenta bancaria no encontrada'], 422);
            }
            // Respetar habilitado_QR explícito para evitar confusiones en reportes
            $habilitadoQr = false;
            try { $habilitadoQr = !!($cta->habilitado_QR ?? false); } catch (\Throwable $e) { $habilitadoQr = false; }
            if (!$habilitadoQr) {
                return response()->json(['success' => false, 'message' => 'La cuenta seleccionada no está habilitada para QR'], 422);
            }
            // Aplicar overrides si existen (mantiene compatibilidad con config global)
            $set = function(string $key, $val): void { if ($val !== null && $val !== '') { config([$key => $val]); } };
            $set('qr.url_auth', $cta->qr_url_auth ?? null);
            $set('qr.api_key', $cta->qr_api_key ?? null);
            $set('qr.username', $cta->qr_username ?? null);
            $set('qr.password', $cta->qr_password ?? null);
            $set('qr.url_transfer', $cta->qr_url_transfer ?? null);
            $set('qr.api_key_servicio', $cta->qr_api_key_servicio ?? null);
            if (isset($cta->qr_http_verify_ssl)) { config(['qr.http_verify_ssl' => (bool)$cta->qr_http_verify_ssl]); }
            if (isset($cta->qr_http_timeout) && is_numeric($cta->qr_http_timeout)) { config(['qr.http_timeout' => (int)$cta->qr_http_timeout]); }
            if (isset($cta->qr_http_connect_timeout) && is_numeric($cta->qr_http_connect_timeout)) { config(['qr.http_connect_timeout' => (int)$cta->qr_http_connect_timeout]); }
            \Illuminate\Support\Facades\Log::info('QR initiate: applied account overrides if present', [
                'id_cuentas_bancarias' => (int)$validated['id_cuentas_bancarias'],
                'has_overrides' => !!(($cta->qr_url_auth ?? null) || ($cta->qr_api_key ?? null) || ($cta->qr_url_transfer ?? null) || ($cta->qr_api_key_servicio ?? null)),
                'habilitado_QR' => $habilitadoQr,
                'doc_pref' => $cta->doc_tipo_preferido ?? null,
                'I_R' => $cta->I_R ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('QR initiate: error loading account overrides', ['err' => $e->getMessage()]);
        }

        $alias = $validated['cod_ceta'] . date('HisdmY');
        $callback = rtrim((string)config('qr.callback_base'), '/') . '/api/qr/callback';
        Log::info('QR initiate: using callback URL', ['callback' => $callback]);
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
        $docMarker = '';
        try {
            $itms = $validated['items'] ?? [];
            foreach ($itms as $itX) {
                $td = strtoupper((string)($itX['tipo_documento'] ?? ''));
                if ($td === 'F') { $docMarker = ' [DOC:F]'; break; }
            }
        } catch (\Throwable $e) {}
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
            'moneda' => (string)$validated['moneda'],
            'gestion' => isset($validated['gestion']) ? (string)$validated['gestion'] : null,
            'estado' => 'generado',
            'detalle_glosa' => (string)$validated['detalle'] . $docMarker,
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
            $pu = isset($it['pu_mensualidad']) ? (float)$it['pu_mensualidad'] : $monto;
            $concepto = (string)($it['concepto'] ?? ($validated['detalle'] . ' #' . ($idx+1)));
            $nroCuota = $it['nro_cuota'] ?? $it['numero_cuota'] ?? null;
            $turno = $it['turno'] ?? null;
            $montoSaldo = $it['monto_saldo'] ?? null;
            DB::table('qr_conceptos_detalle')->insert([
                'id_qr_transaccion' => $idQr,
                'tipo_concepto' => (string)($it['tipo_concepto'] ?? 'GENERAL'),
                'nro_cobro' => $it['nro_cobro'] ?? null,
                'concepto' => $concepto,
                'observaciones' => $it['observaciones'] ?? null,
                'precio_unitario' => $pu,
                'subtotal' => $monto,
                'orden' => (int)($it['order'] ?? ($idx+1)),
                'nro_cuota' => $nroCuota !== null ? (int)$nroCuota : null,
                'turno' => $turno !== null ? (string)$turno : null,
                'monto_saldo' => $montoSaldo !== null ? (int)$montoSaldo : null,
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

        // Decidir dummy only si faltan configs o si force_dummy=true
        $env = (string)config('qr.environment', app()->environment());
        $missingCfg = empty(config('qr.url_auth')) || empty(config('qr.api_key')) || empty(config('qr.url_transfer')) || empty(config('qr.api_key_servicio'));
        Log::info('QR initiate env/config', [
            'env' => $env,
            'force_dummy' => (bool)config('qr.force_dummy', false),
            'missingCfg' => $missingCfg,
            'url_auth_set' => !empty(config('qr.url_auth')),
            'url_transfer_set' => !empty(config('qr.url_transfer')),
        ]);
        if ($missingCfg || (bool)config('qr.force_dummy', false)) {
            $dummyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9sCYb5QAAAAASUVORK5CYII=';
            try {
                DB::table('qr_transacciones')->where('id_qr_transaccion', $idQr)->update([
                    'imagenQr' => base64_decode($dummyPngBase64),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) { /* noop */ }
            Log::warning('QR using dummy image', ['reason' => $missingCfg ? 'missing_config' : 'force_dummy']);
            return response()->json([
                'success' => true,
                'data' => [
                    'id_qr_transaccion' => $idQr,
                    'alias' => $alias,
                    'qr_image_base64' => $dummyPngBase64,
                    'qr_url' => null,
                    'external_id' => null,
                    'amount' => (float)$validated['amount'],
                    'expires_at' => $fechaExpiracion,
                ],
                'meta' => ['mode' => 'dummy']
            ]);
        }

        // Llamar proveedor para generar QR
        Log::info('QR authenticate() call');
        $auth = $gateway->authenticate();
        if (!$auth['ok']) {
            Log::error('QR auth failed', [
                'url_auth_set' => !empty(config('qr.url_auth')),
                'api_key_set' => !empty(config('qr.api_key')),
            ]);
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
        Log::info('QR createPayment() request', [
            'transfer_base' => rtrim((string)config('qr.url_transfer'), '/'),
            'payload' => $provReq,
        ]);
        $resp = $gateway->createPayment($auth['token'], $provReq, (string)config('qr.api_key_servicio'));
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
        if (is_string($qrBase64) && str_starts_with($qrBase64, 'data:image')) {
            $pos = strpos($qrBase64, ',');
            if ($pos !== false) {
                $qrBase64 = substr($qrBase64, $pos + 1);
                Log::info('QR imagen sanitized from data URL');
            }
        }
        $provTransId = $data['objeto']['idTransaccion'] ?? null;
        $provQrId = $data['objeto']['idQr'] ?? null;
        $fechaVencResp = $data['objeto']['fechaVencimiento'] ?? null;
        $qrUrl = $data['objeto']['url'] ?? null; // si existiera
        $nroAut = $data['objeto']['numeroAutorizacion'] ?? null;
        Log::info('QR createPayment() response summary', [
            'codigo' => $codigo,
            'has_imagen' => $qrBase64 ? true : false,
            'img_len' => $qrBase64 ? strlen((string)$qrBase64) : 0,
            'idTransaccion' => $provTransId,
            'idQr' => $provQrId,
            'fechaVencimiento' => $fechaVencResp,
            'qr_url' => $qrUrl ? true : false,
        ]);

        DB::table('qr_transacciones')
            ->where('id_qr_transaccion', $idQr)
            ->update([
                'codigo_qr' => $provQrId ? (string)$provQrId : null,
                'nro_transaccion' => $provTransId ? (int)$provTransId : null,
                'imagenQr' => $qrBase64 ? base64_decode($qrBase64) : null,
                'nro_autorizacion' => $nroAut,
                'updated_at' => now(),
            ]);
        try {
            $saved = DB::table('qr_transacciones')->select('imagenQr')->where('id_qr_transaccion', $idQr)->first();
            $savedLen = ($saved && isset($saved->imagenQr) && $saved->imagenQr !== null) ? strlen($saved->imagenQr) : 0;
            Log::info('QR imagen saved bytes', ['bytes' => $savedLen]);
        } catch (\Throwable $e) {}

        // Fallback de imagen: si no llegó base64 del proveedor, intentar desde DB (imagenQr BLOB)
        if (!$qrBase64) {
            try {
                $rowImg = DB::table('qr_transacciones')->select('imagenQr')->where('id_qr_transaccion', $idQr)->first();
                if ($rowImg && isset($rowImg->imagenQr) && $rowImg->imagenQr !== null) {
                    $qrBase64 = base64_encode($rowImg->imagenQr);
                }
            } catch (\Throwable $e) {}
            Log::warning('QR response missing image, using DB fallback', ['has_db_img' => isset($rowImg) && $rowImg && $rowImg->imagenQr !== null]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id_qr_transaccion' => $idQr,
                'alias' => $alias,
                'qr_image_base64' => $qrBase64,
                'qr_url' => $qrUrl,
                'external_id' => $provTransId,
                'amount' => (float)$validated['amount'],
                'expires_at' => $fechaExpiracion,
            ],
        ]);
    }

    public function callback(Request $request, QrGatewayService $gateway, \App\Services\Qr\QrSocketNotifier $notifier)
    {
        Log::info('QR callback hit', [
            'method' => $request->method(),
            'has_alias' => $request->has('alias'),
            'has_idQr' => $request->has('idQr'),
            'remote_addr' => $request->ip(),
        ]);
        $cbUser = (string)config('qr.callback_basic_user');
        $cbPass = (string)config('qr.callback_basic_pass');
        $isProd = (string)config('qr.environment') === 'production';
        if ($isProd) {
            $auth = (string)$request->header('Authorization', '');
            if (!str_starts_with($auth, 'Basic ')) {
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
            // 1) Fallback por idQr (algunos callbacks del proveedor envían solo idQr)
            $idQrCb = trim((string)$request->input('idQr', ''));
            if ($idQrCb !== '') {
                $byIdQr = DB::table('qr_transacciones')
                    ->where('codigo_qr', $idQrCb)
                    ->orderByDesc('created_at')
                    ->first();
                if ($byIdQr) { $alias = (string)$byIdQr->alias; }
            }

            // 2) Fallback opcional por cod_ceta en entornos no productivos
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
        $extIdQr = (string)$request->input('idQr', '');
        $formaCobro = (string)config('qr.forma_cobro_id', '');
        if ($formaCobro === '') {
            try {
                $rowFc = DB::table('formas_cobro')
                    ->whereRaw("UPPER(REPLACE(REPLACE(nombre,'Á','A'),'É','E')) LIKE '%TRANSFERENCIA%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(descripcion,'Á','A'),'É','E')) LIKE '%TRANSFERENCIA%'")
                    ->orderBy('id_forma_cobro')
                    ->first();
                if ($rowFc && isset($rowFc->id_forma_cobro)) {
                    $formaCobro = (string)$rowFc->id_forma_cobro;
                }
            } catch (\Throwable $e) {}
        }
        if ($formaCobro === '') { $formaCobro = 'B'; }

        $estadoAnterior = (string)$trx->estado;
        $map = [
            'PAGADO' => 'completado',
            'PROCESANDO' => 'procesando',
            'PENDIENTE' => 'procesando',
            'CANCELADO' => 'cancelado',
            'EXPIRADO' => 'expirado',
        ];
        $estadoNuevo = $map[$statusExt] ?? 'completado';

        // Guardar traza de callback en respuestas_banco (lo que venga del proveedor)
        DB::table('qr_respuestas_banco')->insert([
            'id_qr_transaccion' => $trx->id_qr_transaccion,
            'banco' => 'SIP',
            'codigo_respuesta' => (string)$request->input('codigo', '0000'),
            'mensaje_respuesta' => (string)$request->input('mensaje', 'callback'),
            'alias' => (string)$request->input('alias', $alias),
            'numeroordenoriginante' => (string)$request->input('numeroOrdenOriginante', ''),
            'monto' => $request->has('monto') ? (float)$request->input('monto') : null,
            'id_qr' => (string)$request->input('idQr', ''),
            'moneda' => (string)$request->input('moneda', ''),
            'fecha_proceso' => (string)($request->input('fechaProceso', '') ?: date('Y-m-d H:i:s', strtotime((string)($trx->fecha_generacion ?? date('Y-m-d H:i:s'))))),
            'cuentaCliente' => (string)$request->input('cuentaCliente', ''),
            'nombreCliente' => (string)$request->input('nombreCliente', ''),
            'documentoCliente' => (string)$request->input('documentoCliente', ''),
            'observaciones' => (string)$request->input('observaciones', ''),
            'numero_autorizacion' => (string)$request->input('numeroAutorizacion', ''),
            'numero_referencia' => (string)$request->input('numeroReferencia', $request->input('numeroOrdenOriginante', '')),
            'numero_comprobante' => (string)$request->input('numeroComprobante', $request->input('idQr', '')),
            'fecha_respuesta' => now(),
        ]);

        // Actualizar estado + id externo si llegó
        DB::table('qr_transacciones')
            ->where('id_qr_transaccion', $trx->id_qr_transaccion)
            ->update([
                'estado' => $estadoNuevo,
                'codigo_qr' => $extIdQr !== '' ? (string)$extIdQr : $trx->codigo_qr,
                'numeroordenoriginante' => $request->input('numeroOrdenOriginante') ? (int)$request->input('numeroOrdenOriginante') : $trx->numeroordenoriginante,
                'nro_transaccion' => $extId !== '' ? (int)$extId : $trx->nro_transaccion,
                'cuenta_cliente' => (string)$request->input('cuentaCliente', $trx->cuenta_cliente),
                'nombre_cliente' => (string)$request->input('nombreCliente', $trx->nombre_cliente),
                'documento_cliente' => $request->input('documentoCliente') ? (int)$request->input('documentoCliente') : $trx->documento_cliente,
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
                DB::transaction(function () use ($trx, $formaCobro, $alias, $extIdQr) {
                    // Bloqueo por fila para idempotencia
                    $locked = DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->lockForUpdate()->first();
                    // 0) Guardia por columna opcional (si existe)
                    if ($locked && isset($locked->batch_procesado_at) && $locked->batch_procesado_at !== null) {
                        Log::info('QR batchStore skipped (already processed)', ['alias' => $alias, 'id_qr_transaccion' => $trx->id_qr_transaccion]);
                        return;
                    }
                    // 1) Guardia por log (no requiere columnas nuevas)
                    $alreadyLogged = DB::table('qr_estados_log')
                        ->where('id_qr_transaccion', $trx->id_qr_transaccion)
                        ->where('motivo_cambio', 'callback_batch_done')
                        ->exists();
                    if ($alreadyLogged) {
                        Log::info('QR batchStore skipped (already logged done)', ['alias' => $alias, 'id_qr_transaccion' => $trx->id_qr_transaccion]);
                        return;
                    }
                    $marker = '[QR] alias:' . $alias;
                    $marker2 = '[QR TRANSFERENCIA] alias:' . $alias;
                    $existsCobro = DB::table('cobro')
                        ->where('cod_ceta', (int)$trx->cod_ceta)
                        ->where('id_forma_cobro', $formaCobro)
                        ->whereDate('fecha_cobro', date('Y-m-d'))
                        ->where(function($q) use ($marker, $marker2){ $q->where('observaciones', 'like', "%$marker%")->orWhere('observaciones', 'like', "%$marker2%"); })
                        ->exists();
                    if ($existsCobro) {
                        Log::info('QR batchStore skipped (cobro already exists today)', ['alias' => $alias, 'id_qr_transaccion' => $trx->id_qr_transaccion]);
                        $marker = '%[QR TRANSFERENCIA] alias:' . $alias . '%';
                        $marker2 = '%[QR] alias:' . $alias . '%';
                        try {
                            $cob = DB::table('cobro')
                                ->where('cod_ceta', (int)$trx->cod_ceta)
                                ->where('id_forma_cobro', $formaCobro)
                                ->whereDate('fecha_cobro', date('Y-m-d'))
                                ->where(function($q) use ($marker, $marker2){ $q->where('observaciones', 'like', $marker)->orWhere('observaciones', 'like', $marker2); })
                                ->orderByDesc('created_at')
                                ->first();
                            if ($cob) {
                                $anioDoc = (int)date('Y', strtotime((string)($cob->fecha_cobro ?? date('Y-m-d'))));
                                $upd = [];
                                if (!empty($cob->nro_recibo) && is_numeric($cob->nro_recibo)) { $upd['nro_recibo'] = (int)$cob->nro_recibo; $upd['anio_recibo'] = $anioDoc; }
                                if (!empty($cob->nro_factura) && is_numeric($cob->nro_factura)) { $upd['nro_factura'] = (int)$cob->nro_factura; $upd['anio'] = $anioDoc; }
                                if (!empty($upd)) {
                                    $aff = DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update(array_merge(['batch_procesado_at' => DB::raw('COALESCE(batch_procesado_at, NOW())'), 'updated_at' => now()], $upd));
                                    Log::info('QR docnums updated from existing cobro', ['alias' => $alias, 'upd' => $upd, 'affected' => $aff]);
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::warning('QR update from existing cobro failed', ['alias' => $alias, 'err' => $e->getMessage()]);
                        }
                        DB::table('qr_estados_log')->insert([
                            'id_qr_transaccion' => $trx->id_qr_transaccion,
                            'estado_anterior' => 'completado',
                            'estado_nuevo' => 'completado',
                            'motivo_cambio' => 'callback_batch_done',
                            'usuario' => null,
                            'fecha_cambio' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        return;
                    }

                    // 2b) Guardia por cobro similar reciente SIN marcador (posible creación manual desde UI)
                    $existsSimilar = DB::table('cobro')
                        ->where('cod_ceta', (int)$trx->cod_ceta)
                        ->where('id_forma_cobro', $formaCobro)
                        ->whereDate('fecha_cobro', date('Y-m-d'))
                        ->whereBetween('created_at', [now()->subMinutes(10), now()->addMinutes(1)])
                        ->whereRaw('ABS(monto - ?) < 0.001', [(float)$trx->monto_total])
                        ->exists();
                    if ($existsSimilar) {
                        Log::info('QR batchStore skipped (similar cobro recently created without marker)', ['alias' => $alias, 'id_qr_transaccion' => $trx->id_qr_transaccion]);
                        try {
                            $cob = DB::table('cobro')
                                ->where('cod_ceta', (int)$trx->cod_ceta)
                                ->where('id_forma_cobro', $formaCobro)
                                ->whereDate('fecha_cobro', date('Y-m-d'))
                                ->whereBetween('created_at', [now()->subMinutes(20), now()->addMinutes(1)])
                                ->whereRaw('ABS(monto - ?) < 0.001', [(float)$trx->monto_total])
                                ->orderByDesc('created_at')
                                ->first();
                            if ($cob) {
                                $anioDoc = (int)date('Y', strtotime((string)($cob->fecha_cobro ?? date('Y-m-d'))));
                                $upd = [];
                                if (!empty($cob->nro_recibo) && is_numeric($cob->nro_recibo)) { $upd['nro_recibo'] = (int)$cob->nro_recibo; $upd['anio_recibo'] = $anioDoc; }
                                if (!empty($cob->nro_factura) && is_numeric($cob->nro_factura)) { $upd['nro_factura'] = (int)$cob->nro_factura; $upd['anio'] = $anioDoc; }
                                if (!empty($upd)) {
                                    $aff = DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update(array_merge(['batch_procesado_at' => DB::raw('COALESCE(batch_procesado_at, NOW())'), 'updated_at' => now()], $upd));
                                    Log::info('QR docnums updated from similar cobro', ['alias' => $alias, 'upd' => $upd, 'affected' => $aff]);
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::warning('QR update from similar cobro failed', ['alias' => $alias, 'err' => $e->getMessage()]);
                        }
                        DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                            'batch_procesado_at' => DB::raw('COALESCE(batch_procesado_at, NOW())'),
                            'updated_at' => now(),
                        ]);
                        DB::table('qr_estados_log')->insert([
                            'id_qr_transaccion' => $trx->id_qr_transaccion,
                            'estado_anterior' => 'completado',
                            'estado_nuevo' => 'completado',
                            'motivo_cambio' => 'callback_batch_done',
                            'usuario' => null,
                            'fecha_cambio' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        return;
                    }

                    $obsMarker = '[QR TRANSFERENCIA] alias:' . $alias . ($extIdQr ? (' idQr:' . $extIdQr) : '');
                    $tipoDoc = 'R';
                    try {
                        $tipReq = strtoupper((string)$request->input('tipo_documento', ''));
                        if ($tipReq === 'F') { $tipoDoc = 'F'; }
                        else {
                            $glosa = (string)($trx->detalle_glosa ?? '');
                            if (stripos($glosa, '[DOC:F]') !== false) { $tipoDoc = 'F'; }
                        }
                    } catch (\Throwable $e) {}
                    $puMensualidad = (float)$trx->monto_total;
                    try {
                        $rowPu = DB::table('qr_conceptos_detalle')
                            ->where('id_qr_transaccion', $trx->id_qr_transaccion)
                            ->orderByDesc('precio_unitario')
                            ->value('precio_unitario');
                        if ($rowPu !== null) { $puMensualidad = (float)$rowPu; }
                    } catch (\Throwable $e) {}
                    $items = [[
                        'monto' => (float)$trx->monto_total,
                        'fecha_cobro' => date('Y-m-d'),
                        'order' => 1,
                        'observaciones' => trim($obsMarker . ' | ' . (string)$trx->detalle_glosa),
                        'pu_mensualidad' => $puMensualidad,
                        'id_forma_cobro' => $formaCobro,
                        'tipo_documento' => $tipoDoc,
                        'medio_doc' => 'C',
                    ]];

                    // Incluir cuenta bancaria solo si existe realmente (evita fallo de validación exists)
                    $maybeAccountId = (int)($trx->id_cuenta_bancaria ?? 0);
                    $accountExists = $maybeAccountId > 0 && DB::table('cuentas_bancarias')
                        ->where('id_cuentas_bancarias', $maybeAccountId)
                        ->exists();

                    $payload = [
                        'cod_ceta' => (int)$trx->cod_ceta,
                        'cod_pensum' => (string)$trx->cod_pensum,
                        'tipo_inscripcion' => (string)$trx->tipo_inscripcion,
                        'gestion' => null,
                        'id_usuario' => (int)$trx->id_usuario,
                        'id_forma_cobro' => $formaCobro,
                        'items' => $items,
                        'qr_context' => true,
                    ];
                    if ($accountExists) {
                        $payload['id_cuentas_bancarias'] = $maybeAccountId;
                    }
                    Log::info('QR callback payload to batchStore', [
                        'alias' => $alias,
                        'id_qr_transaccion' => $trx->id_qr_transaccion,
                        'tipo_documento' => $tipoDoc,
                        'items' => $items,
                    ]);

                    $req = Request::create('/api/cobros/batch', 'POST', $payload);
                    $controller = app(CobroController::class);
                    $resp = app()->call([$controller, 'batchStore'], ['request' => $req]);
                    $ok = false;
                    if ($resp instanceof \Illuminate\Http\JsonResponse) {
                        $body = $resp->getData(true);
                        $ok = !!($body['success'] ?? false);
                        if (!$ok) {
                            Log::warning('QR batchStore validation failed', [
                                'alias' => $alias,
                                'errors' => $body['errors'] ?? $body,
                            ]);
                        } else {
                            try { Log::info('QR batchStore OK', ['alias' => $alias, 'items' => $body['data']['items'] ?? []]); } catch (\Throwable $e) {}
                        }
                    }

                    // Marcar como procesado solo si batch fue exitoso y guardar nro de documento
                    if ($ok) {
                        $docUpd = [];
                        try {
                            $itemsResp = $body['data']['items'] ?? [];
                            $firstItem = is_array($itemsResp) && count($itemsResp) > 0 ? $itemsResp[0] : null;
                            if (is_array($firstItem)) {
                                $tipoDoc = strtoupper((string)($firstItem['tipo_documento'] ?? ''));
                                $nroRec = $firstItem['nro_recibo'] ?? null;
                                $nroFac = $firstItem['nro_factura'] ?? null;
                                $cobroObj = $firstItem['cobro'] ?? [];
                                $fechaCobro = '';
                                if (is_array($cobroObj)) { $fechaCobro = (string)($cobroObj['fecha_cobro'] ?? ''); }
                                $anioDoc = $fechaCobro ? (int)date('Y', strtotime($fechaCobro)) : (int)date('Y');
                                if ($tipoDoc === 'R' && is_numeric($nroRec)) {
                                    $docUpd['nro_recibo'] = (int)$nroRec;
                                    $docUpd['anio_recibo'] = $anioDoc;
                                } elseif ($tipoDoc === 'F' && is_numeric($nroFac)) {
                                    $docUpd['nro_factura'] = (int)$nroFac;
                                    $docUpd['anio'] = $anioDoc;
                                }
                            }
                        } catch (\Throwable $e) {}
                        $aff = DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update(array_merge([
                            'batch_procesado_at' => now(),
                            'updated_at' => now(),
                        ], $docUpd));
                        Log::info('QR docnums updated from batch response', ['alias' => $alias, 'upd' => $docUpd, 'affected' => $aff]);
                        // Registrar marca idempotente basada en log
                        DB::table('qr_estados_log')->insert([
                            'id_qr_transaccion' => $trx->id_qr_transaccion,
                            'estado_anterior' => 'completado',
                            'estado_nuevo' => 'completado',
                            'motivo_cambio' => 'callback_batch_done',
                            'usuario' => null,
                            'fecha_cambio' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        // Intentar backfill cuando la guardia 409 impide crear desde callback
                        try {
                            $cob = DB::table('cobro')
                                ->where('cod_ceta', (int)$trx->cod_ceta)
                                ->whereDate('fecha_cobro', date('Y-m-d'))
                                ->whereBetween('created_at', [now()->subMinutes(30), now()->addMinutes(1)])
                                ->whereRaw('ABS(monto - ?) < 0.001', [(float)$trx->monto_total])
                                ->orderByDesc('created_at')
                                ->first();
                            if ($cob) {
                                $anioDoc = (int)date('Y', strtotime((string)($cob->fecha_cobro ?? date('Y-m-d'))));
                                $docUpd = [];
                                if (!empty($cob->nro_recibo) && is_numeric($cob->nro_recibo)) { $docUpd['nro_recibo'] = (int)$cob->nro_recibo; $docUpd['anio_recibo'] = $anioDoc; }
                                if (!empty($cob->nro_factura) && is_numeric($cob->nro_factura)) { $docUpd['nro_factura'] = (int)$cob->nro_factura; $docUpd['anio'] = $anioDoc; }
                                if (!empty($docUpd)) {
                                    $aff = DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update(array_merge([
                                        'batch_procesado_at' => DB::raw('COALESCE(batch_procesado_at, NOW())'),
                                        'updated_at' => now(),
                                    ], $docUpd));
                                    Log::info('QR docnums backfilled after 409', ['alias' => $alias, 'upd' => $docUpd, 'affected' => $aff]);
                                } else {
                                    Log::info('QR backfill after 409 found cobro sin docnums', ['alias' => $alias]);
                                }
                            } else {
                                Log::info('QR backfill after 409 no cobro found yet', ['alias' => $alias]);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('QR backfill after 409 failed', ['alias' => $alias, 'err' => $e->getMessage()]);
                        }
                    }
                });
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
        // Aplicar overrides por cuenta de la transacción
        try { $this->applyAccountOverrides((int)($trx->id_cuenta_bancaria ?? 0)); } catch (\Throwable $e) {}

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
        // Aplicar overrides por cuenta de la transacción
        try { $this->applyAccountOverrides((int)($trx->id_cuenta_bancaria ?? 0)); } catch (\Throwable $e) {}

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

    public function syncByCodCeta(Request $request, QrGatewayService $gateway)
    {
        $cod = (int)$request->input('cod_ceta');
        if (!$cod) { return response()->json(['success' => false, 'message' => 'cod_ceta requerido'], 422); }
        $trx = DB::table('qr_transacciones')
            ->where('cod_ceta', $cod)
            ->orderByDesc('created_at')
            ->first();
        if (!$trx) { return response()->json(['success' => true, 'data' => null]); }
        $alias = (string)$trx->alias;
        $estado = (string)$trx->estado;
        if (in_array($estado, ['completado','cancelado','expirado'], true)) {
            return response()->json(['success' => true, 'data' => ['alias' => $alias, 'estado' => $estado]]);
        }
        // Aplicar overrides por cuenta de la transacción
        try { $this->applyAccountOverrides((int)($trx->id_cuenta_bancaria ?? 0)); } catch (\Throwable $e) {}
        $auth = $gateway->authenticate();
        if (!$auth['ok']) { return response()->json(['success' => true, 'data' => ['alias' => $alias, 'estado' => $estado]]); }
        $resp = $gateway->getStatus($auth['token'], $alias);
        if (!$resp['ok']) { return response()->json(['success' => true, 'data' => ['alias' => $alias, 'estado' => $estado]]); }
        $payload = $resp['data'] ?? [];
        $estadoExt = strtoupper((string)($payload['objeto']['estadoActual'] ?? ''));
        $map = [
            'PAGADO' => 'completado',
            'INHABILITADO' => 'cancelado',
            'ERROR' => 'cancelado',
            'EXPIRADO' => 'expirado',
            'PENDIENTE' => 'procesando',
        ];
        $nuevo = $map[$estadoExt] ?? $estado;
        if ($nuevo !== $estado) {
            DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                'estado' => $nuevo,
                'updated_at' => now(),
            ]);
            DB::table('qr_estados_log')->insert([
                'id_qr_transaccion' => $trx->id_qr_transaccion,
                'estado_anterior' => $estado,
                'estado_nuevo' => $nuevo,
                'motivo_cambio' => 'sync_cod_ceta',
                'usuario' => (string)($request->input('id_usuario') ?? ''),
                'fecha_cambio' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return response()->json(['success' => true, 'data' => ['alias' => $alias, 'estado' => $nuevo, 'payload' => $payload]]);
    }

    public function stateByCodCeta(Request $request)
    {
        $codParam = $request->input('cod_ceta', $request->query('cod_ceta', $request->route('cod_ceta')));
        $cod = (int)$codParam;
        if (!$cod) { return response()->json(['success' => false, 'message' => 'cod_ceta requerido'], 422); }
        $trx = DB::table('qr_transacciones')
            ->where('cod_ceta', $cod)
            ->orderByDesc('created_at')
            ->first();
        if (!$trx) { return response()->json(['success' => true, 'data' => null]); }
        return response()->json(['success' => true, 'data' => [
            'alias' => (string)$trx->alias,
            'estado' => (string)$trx->estado,
            'id_qr_transaccion' => (int)$trx->id_qr_transaccion,
            'updated_at' => (string)$trx->updated_at,
        ]]);
    }

    public function transactions(Request $request)
    {
        $limit = max(1, min(200, (int)$request->query('limit', 50)));
        $page = max(1, (int)$request->query('page', 1));
        $q = DB::table('qr_transacciones');
        if ($request->filled('cod_ceta')) { $q->where('cod_ceta', (int)$request->query('cod_ceta')); }
        if ($request->filled('alias')) { $q->where('alias', $request->query('alias')); }
        if ($request->filled('estado')) { $q->where('estado', $request->query('estado')); }
        if ($request->filled('desde')) { $q->whereDate('fecha_generacion', '>=', $request->query('desde')); }
        if ($request->filled('hasta')) { $q->whereDate('fecha_generacion', '<=', $request->query('hasta')); }
        $total = (clone $q)->count();
        $items = $q->orderByDesc('fecha_generacion')->limit($limit)->offset(($page - 1) * $limit)->get();
        // Evitar error de UTF-8 malformado por BLOB (imagenQr)
        $items = $items->map(function ($row) {
            if (isset($row->imagenQr)) {
                $row->has_imagen = $row->imagenQr !== null;
                unset($row->imagenQr);
            }
            return $row;
        });
        return response()->json(['success' => true, 'data' => ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit]]);
    }

    public function transactionDetail($id)
    {
        $trx = DB::table('qr_transacciones')->where('id_qr_transaccion', (int)$id)->first();
        if (!$trx) { return response()->json(['success' => false, 'message' => 'Not found'], 404); }
        // Base64 de imagen y remover BLOB para JSON seguro
        if (isset($trx->imagenQr)) {
            $trx->qr_image_base64 = $trx->imagenQr !== null ? base64_encode($trx->imagenQr) : null;
            unset($trx->imagenQr);
        }
        $conceptos = DB::table('qr_conceptos_detalle')->where('id_qr_transaccion', (int)$id)->orderBy('orden')->get();
        $estados = DB::table('qr_estados_log')->where('id_qr_transaccion', (int)$id)->orderByDesc('fecha_cambio')->get();
        $respuestas = DB::table('qr_respuestas_banco')->where('id_qr_transaccion', (int)$id)->orderByDesc('fecha_respuesta')->get();
        return response()->json(['success' => true, 'data' => ['transaccion' => $trx, 'conceptos' => $conceptos, 'estados' => $estados, 'respuestas' => $respuestas]]);
    }

    public function configList(Request $request)
    {
        $q = DB::table('qr_configuracion');
        if ($request->filled('cod_pensum')) { $q->where('cod_pensum', (string)$request->query('cod_pensum')); }
        $rows = $q->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function configUpsert(Request $request)
    {
        $validated = $request->validate([
            'cod_pensum' => 'nullable|string|max:50',
            'tiempo_expiracion_minutos' => 'nullable|integer|min:1|max:10080',
            'monto_minimo' => 'nullable|numeric|min:0',
            'permite_pago_parcial' => 'nullable|boolean',
            'template_mensaje' => 'nullable|string',
            'estado' => 'nullable|boolean',
        ]);
        $key = ['cod_pensum' => $validated['cod_pensum'] ?? null];
        $data = [
            'tiempo_expiracion_minutos' => $validated['tiempo_expiracion_minutos'] ?? 1440,
            'monto_minimo' => $validated['monto_minimo'] ?? 200,
            'permite_pago_parcial' => $validated['permite_pago_parcial'] ?? false,
            'template_mensaje' => $validated['template_mensaje'] ?? null,
            'estado' => $validated['estado'] ?? true,
        ];
        DB::table('qr_configuracion')->updateOrInsert($key, $data);
        $row = DB::table('qr_configuracion')->where('cod_pensum', $key['cod_pensum'])->first();
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function respuestasList(Request $request)
    {
        $q = DB::table('qr_respuestas_banco');
        if ($request->filled('id_qr_transaccion')) { $q->where('id_qr_transaccion', (int)$request->query('id_qr_transaccion')); }
        if ($request->filled('alias')) { $q->where('alias', (string)$request->query('alias')); }
        $rows = $q->orderByDesc('fecha_respuesta')->limit(200)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }
}
