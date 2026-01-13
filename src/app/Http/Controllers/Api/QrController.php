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
    private function applyAccountOverrides($idCuenta)
    {
        if ($idCuenta <= 0) return;
        try {
            $cta = DB::table('cuentas_bancarias')->where('id_cuentas_bancarias', $idCuenta)->first();
            if (!$cta) return;
            $set = function($key, $val) { if ($val !== null && $val !== '') { config([$key => $val]); } };
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

    public function saveLote(Request $request)
    {
        $validated = $request->validate([
            'alias' => 'nullable|string|max:100',
            'cod_ceta' => 'nullable|integer',
            'id_usuario' => 'nullable|integer',
            'id_cuentas_bancarias' => 'nullable',
            'moneda' => 'nullable|string|max:10',
            'gestion' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.monto' => 'required|numeric|min:0.01',
            'items.*.detalle' => 'nullable|string',
            'items.*.observaciones' => 'nullable|string',
            'items.*.order' => 'nullable|integer',
            'items.*.pu_mensualidad' => 'nullable|numeric|min:0',
            'items.*.nro_cuota' => 'nullable|integer',
            'items.*.turno' => 'nullable|string',
            'items.*.monto_saldo' => 'nullable|integer',
            'items.*.id_forma_cobro' => 'nullable',
        ]);

        $alias = (string)($validated['alias'] ?? '');
        $trx = null;
        if ($alias !== '') {
            $trx = DB::table('qr_transacciones')->where('alias', $alias)->first();
        } else if (!empty($validated['cod_ceta'])) {
            $trx = DB::table('qr_transacciones')
                ->where('cod_ceta', (int)$validated['cod_ceta'])
                ->whereIn('estado', ['generado','procesando'])
                ->orderByDesc('created_at')
                ->first();
        }
        if (!$trx) {
            return response()->json(['success' => false, 'message' => 'QR no encontrado para guardar lote'], 404);
        }

        try {
            DB::transaction(function () use ($trx, $validated) {
                // Reemplazar snapshot
                DB::table('qr_conceptos_detalle')->where('id_qr_transaccion', $trx->id_qr_transaccion)->delete();
                $total = 0.0;
                $items = $validated['items'] ?? [];
                foreach ($items as $idx => $it) {
                    $monto = (float)($it['monto'] ?? 0);
                    $pu = isset($it['pu_mensualidad']) ? (float)$it['pu_mensualidad'] : $monto;
                    $concepto = (string)($it['detalle'] ?? 'COBRO QR');
                    if (mb_strlen($concepto) > 255) { $concepto = mb_substr($concepto, 0, 255); }
                    $obsVal = $it['observaciones'] ?? null;
                    if (is_string($obsVal) && mb_strlen($obsVal) > 2000) { $obsVal = mb_substr($obsVal, 0, 2000); }
                    DB::table('qr_conceptos_detalle')->insert([
                        'id_qr_transaccion' => $trx->id_qr_transaccion,
                        'tipo_concepto' => (string)($it['tipo_concepto'] ?? 'general'),
                        'nro_cobro' => $it['nro_cobro'] ?? null,
                        'concepto' => $concepto,
                        'observaciones' => $obsVal,
                        'precio_unitario' => $pu,
                        'subtotal' => $monto,
                        'orden' => (int)($it['order'] ?? ($idx+1)),
                        'nro_cuota' => isset($it['nro_cuota']) ? (int)$it['nro_cuota'] : null,
                        'turno' => $it['turno'] ?? null,
                        'monto_saldo' => isset($it['monto_saldo']) ? (int)$it['monto_saldo'] : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $total += $monto;
                }
                $update = [
                    'monto_total' => $total,
                    'updated_at' => now(),
                    'saved_by_user' => 1,
                ];
                if (!empty($validated['moneda'])) { $update['moneda'] = (string)$validated['moneda']; }
                if (!empty($validated['gestion'])) { $update['gestion'] = (string)$validated['gestion']; }
                if (!empty($validated['id_usuario'])) { $update['id_usuario'] = (int)$validated['id_usuario']; }
                if (!empty($validated['id_cuentas_bancarias'])) { $update['id_cuenta_bancaria'] = (string)$validated['id_cuentas_bancarias']; }
                DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update($update);
            });
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error al guardar lote: ' . $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'message' => 'Lote guardado en espera']);
    }

    private function classifyTipoConcepto($it)
    {
        try {
            $src = strtoupper(trim((string)($it['detalle'] ?? $it['concepto'] ?? $it['observaciones'] ?? '')));
            if ($src === '') return 'general';
            if (strpos($src, 'MENSUALIDAD') !== false) return 'mensualidad';
            if (strpos($src, 'REZAGADO') !== false) return 'rezagado';
            if (strpos($src, 'RECUPERACION') !== false) return 'recuperacion';
            if (strpos($src, 'REINCORPOR') !== false) return 'reincorporacion';
            if (strpos($src, 'ARRASTRE') !== false) return 'arrastre';
            if (strpos($src, 'MATERIAL') !== false || strpos($src, 'LIBRO') !== false || strpos($src, 'TEXTO') !== false) return 'material_extra';
            return 'general';
        } catch (\Throwable $e) { return 'general'; }
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
            'cliente' => 'nullable|array',
            'cliente.tipo_identidad' => 'nullable|integer',
            'cliente.numero' => 'nullable|string',
            'cliente.razon_social' => 'nullable|string',
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
        // Extraer datos del cliente
        $clienteData = $validated['cliente'] ?? [];
        $tipoIdentidad = isset($clienteData['tipo_identidad']) ? (int)$clienteData['tipo_identidad'] : null;
        $numeroDocumento = isset($clienteData['numero']) ? (string)$clienteData['numero'] : null;
        $razonSocial = isset($clienteData['razon_social']) ? (string)$clienteData['razon_social'] : null;

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
            'tipo_identidad_cliente' => $tipoIdentidad,
            'documento_cliente' => $numeroDocumento,
            'nombre_cliente' => $razonSocial,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Persistir detalle (si se envía)
        $items = $validated['items'] ?? [];
        foreach ($items as $idx => $it) {
            $monto = (float)($it['monto'] ?? 0);
            $pu = isset($it['pu_mensualidad']) ? (float)$it['pu_mensualidad'] : $monto;
            $concepto = (string)($it['concepto'] ?? $it['detalle'] ?? ($validated['detalle'] . ' #' . ($idx+1)));
            if (mb_strlen($concepto) > 255) { $concepto = mb_substr($concepto, 0, 255); }
            $nroCuota = $it['nro_cuota'] ?? $it['numero_cuota'] ?? null;
            $turno = $it['turno'] ?? null;
            $montoSaldo = $it['monto_saldo'] ?? null;
            $obsVal = $it['observaciones'] ?? null;
            if (is_string($obsVal) && mb_strlen($obsVal) > 2000) { $obsVal = mb_substr($obsVal, 0, 2000); }
            $tipoDoc = (string)($it['tipo_documento'] ?? '');
            $medioDoc = (string)($it['medio_doc'] ?? '');
            try {
                Log::info('QR initiate: guardando item en qr_conceptos_detalle', [
                    'idx' => $idx,
                    'tipo_documento' => $tipoDoc,
                    'medio_doc' => $medioDoc,
                    'concepto' => $concepto
                ]);
            } catch (\Throwable $e) {}
            DB::table('qr_conceptos_detalle')->insert([
                'id_qr_transaccion' => $idQr,
                'tipo_concepto' => (string)($it['tipo_concepto'] ?? $this->classifyTipoConcepto((array)$it)),
                'nro_cobro' => $it['nro_cobro'] ?? null,
                'concepto' => $concepto,
                'observaciones' => $obsVal,
                'precio_unitario' => $pu,
                'subtotal' => $monto,
                'orden' => (int)($it['order'] ?? ($idx+1)),
                'nro_cuota' => $nroCuota !== null ? (int)$nroCuota : null,
                'turno' => $turno !== null ? (string)$turno : null,
                'monto_saldo' => $montoSaldo !== null ? (int)$montoSaldo : null,
                'tipo_documento' => $tipoDoc !== '' ? $tipoDoc : null,
                'medio_doc' => $medioDoc !== '' ? $medioDoc : null,
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
            // Marcar transacción como error para no dejarla en 'generado'
            try { DB::table('qr_transacciones')->where('id_qr_transaccion', $idQr)->update(['estado' => 'error', 'updated_at' => now()]); } catch (\Throwable $e) {}
            return response()->json(['success' => false, 'message' => 'No se pudo autenticar con el proveedor QR. Intente más tarde.'], 500);
        }
        $provReq = [
            'alias' => $alias,
            'callback' => $callback,
            'callbackUrl' => $callback,
            'urlCallback' => $callback,
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
            // Marcar transacción como error
            try { DB::table('qr_transacciones')->where('id_qr_transaccion', $idQr)->update(['estado' => 'error', 'updated_at' => now()]); } catch (\Throwable $e) {}
            $errMsg = 'No se pudo generar el QR. Intente más tarde.';
            if (!empty($resp['data']['mensaje'])) { $errMsg = (string)$resp['data']['mensaje']; }
            return response()->json(['success' => false, 'message' => $errMsg, 'meta' => $resp], 502);
        }
        $data = $resp['data'] ?? [];
        $codigo = $data['codigo'] ?? null;
        if (!in_array($codigo, ['0000', 'OK'], true)) {
            Log::warning('QR provider returned non-success code', ['codigo' => $codigo, 'data' => $data]);
            // Marcar transacción como error
            try { DB::table('qr_transacciones')->where('id_qr_transaccion', $idQr)->update(['estado' => 'error', 'updated_at' => now()]); } catch (\Throwable $e) {}
            $provMsg = (string)($data['mensaje'] ?? 'No se pudo generar el QR. Intente más tarde.');
            return response()->json(['success' => false, 'message' => $provMsg, 'meta' => $data], 502);
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
        $envQr = (string)config('qr.environment', 'development');
        $hasCreds = (trim($cbUser) !== '') && (trim($cbPass) !== '');
        $isAppProd = app()->environment('production');
        $ip = (string)$request->ip();
        $isLocal = in_array($ip, ['127.0.0.1', '::1'], true)
            || (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip) === 1);
        $requireBasic = $isAppProd && $hasCreds && !$isLocal;
        try { Log::info('QR callback auth gate', ['env_qr' => $envQr, 'app_env_prod' => $isAppProd, 'require_basic' => $requireBasic, 'has_creds' => $hasCreds, 'ip' => $ip, 'is_local' => $isLocal]); } catch (\Throwable $e) {}
        if ($requireBasic) {
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
            try {
                $aliasRoute = (string)($request->route('alias') ?? '');
                if ($aliasRoute !== '') { $alias = trim($aliasRoute); }
            } catch (\Throwable $e) { /* noop */ }
        }
        if ($alias === '') {
            // 0) Intentar dentro de 'objeto.*' (algunos proveedores encapsulan datos)
            try {
                $aliasObj = (string)$request->input('objeto.alias', '');
                if ($aliasObj !== '') { $alias = trim($aliasObj); }
            } catch (\Throwable $e) { /* noop */ }
        }
        if ($alias === '') {
            // 1) Fallback por idQr (algunos callbacks del proveedor envían solo idQr)
            $idQrCb = trim((string)($request->input('idQr', $request->input('objeto.idQr', ''))));
            if ($idQrCb !== '') {
                $byIdQr = DB::table('qr_transacciones')
                    ->where('codigo_qr', $idQrCb)
                    ->orderByDesc('created_at')
                    ->first();
                if ($byIdQr) { $alias = (string)$byIdQr->alias; }
            }
        }
        if ($alias === '') {
            // 1b) Fallback por idTransaccion -> qr_transacciones.nro_transaccion
            $idTxCb = trim((string)($request->input('idTransaccion', $request->input('objeto.idTransaccion', ''))));
            if ($idTxCb !== '' && is_numeric($idTxCb)) {
                $byTx = DB::table('qr_transacciones')
                    ->where('nro_transaccion', (int)$idTxCb)
                    ->orderByDesc('created_at')
                    ->first();
                if ($byTx) { $alias = (string)$byTx->alias; }
            }
        }
        if ($alias === '') {
            // 1c) Fallback por numeroOrdenOriginante -> qr_transacciones.numeroordenoriginante
            $idOrd = trim((string)($request->input('numeroOrdenOriginante', $request->input('objeto.numeroOrdenOriginante', ''))));
            if ($idOrd !== '' && is_numeric($idOrd)) {
                $byOrd = DB::table('qr_transacciones')
                    ->where('numeroordenoriginante', (int)$idOrd)
                    ->orderByDesc('created_at')
                    ->first();
                if ($byOrd) { $alias = (string)$byOrd->alias; }
            }
        }
        if ($alias === '') {
            // 2) Fallback opcional por cod_ceta en entornos no productivos
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
        $extIdQr = (string)$request->input('idQr', '');
        // Aplicar overrides por cuenta de la transacción (si existen)
        try { $this->applyAccountOverrides((int)($trx->id_cuenta_bancaria ?? 0)); } catch (\Throwable $e) {}
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
        // Verificar existencia de forma de cobro configurada; si no existe, buscar alternativas (QR/TRANSFERENCIA/DEPOSITO/BANCO)
        try {
            $fcRow = DB::table('formas_cobro')->where('id_forma_cobro', $formaCobro)->first();
            if (!$fcRow) {
                $rowAlt = DB::table('formas_cobro')
                    ->whereRaw("UPPER(REPLACE(REPLACE(nombre,'Á','A'),'É','E')) LIKE '%QR%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(descripcion,'Á','A'),'É','E')) LIKE '%QR%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(nombre,'Á','A'),'É','E')) LIKE '%TRANSFERENCIA%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(descripcion,'Á','A'),'É','E')) LIKE '%TRANSFERENCIA%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(nombre,'Á','A'),'É','E')) LIKE '%DEPOSITO%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(descripcion,'Á','A'),'É','E')) LIKE '%DEPOSITO%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(nombre,'Á','A'),'É','E')) LIKE '%BANCO%'")
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(descripcion,'Á','A'),'É','E')) LIKE '%BANCO%'")
                    ->orderBy('id_forma_cobro')
                    ->first();
                if ($rowAlt && isset($rowAlt->id_forma_cobro)) { $formaCobro = (string)$rowAlt->id_forma_cobro; }
            }
        } catch (\Throwable $e) {}
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
        // Log de cambio de estado (faltante en callback)
        if ($estadoAnterior !== $estadoNuevo) {
            try {
                DB::table('qr_estados_log')->insert([
                    'id_qr_transaccion' => $trx->id_qr_transaccion,
                    'estado_anterior' => $estadoAnterior,
                    'estado_nuevo' => $estadoNuevo,
                    'motivo_cambio' => 'callback_state_change',
                    'usuario' => null,
                    'fecha_cambio' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) { /* noop */ }
        }

        // Contar COMPLETADO previo ANTES de insertar el log actual (para idempotencia)
        $prevCompletadoCount = DB::table('qr_estados_log')
            ->where('id_qr_transaccion', $trx->id_qr_transaccion)
            ->where('estado_nuevo', 'completado')
            ->count();

        if ($estadoNuevo === 'completado') {
            try {
                // Si ya fue procesado, no duplicar
                $alreadyProcessed = (bool)($trx->processed ?? false);
                if (!$alreadyProcessed) {
                    // Construir payload para batchStore desde snapshot de qr_conceptos_detalle
                    $itemsSnap = DB::table('qr_conceptos_detalle')
                        ->where('id_qr_transaccion', $trx->id_qr_transaccion)
                        ->orderBy('orden')
                        ->get();
                    $today = date('Y-m-d');
                    $items = [];
                    foreach ($itemsSnap as $row) {
                        $obsMk = (string)($row->observaciones ?? '');
                        if (stripos($obsMk, '[QR') === false) {
                            $obsMk = ($obsMk !== '' ? ($obsMk . ' | ') : '') . '[QR] alias:' . $alias;
                        }
                        $tipoDocRecuperado = (string)($row->tipo_documento ?? '');
                        $medioDocRecuperado = (string)($row->medio_doc ?? '');
                        try {
                            Log::info('QR callback: recuperando item de qr_conceptos_detalle', [
                                'tipo_documento' => $tipoDocRecuperado,
                                'medio_doc' => $medioDocRecuperado,
                                'concepto' => (string)($row->concepto ?? '')
                            ]);
                        } catch (\Throwable $e) {}
                        $items[] = [
                            'monto' => (float)($row->subtotal ?? 0),
                            'fecha_cobro' => $today,
                            'order' => (int)($row->orden ?? 1),
                            'observaciones' => $obsMk,
                            'nro_cobro' => null,
                            'pu_mensualidad' => (float)($row->precio_unitario ?? ($row->subtotal ?? 0)),
                            'detalle' => (string)($row->concepto ?? ($trx->detalle_glosa ?? 'COBRO QR')),
                            'id_forma_cobro' => (string)$formaCobro,
                            'id_asignacion_costo' => null,
                            'id_cuota' => null,
                            'nro_cuota' => isset($row->nro_cuota) ? (int)$row->nro_cuota : null,
                            'turno' => $row->turno ?? null,
                            'monto_saldo' => isset($row->monto_saldo) ? (int)$row->monto_saldo : null,
                            'tipo_documento' => (string)($row->tipo_documento ?? ''),
                            'medio_doc' => (string)($row->medio_doc ?? ''),
                        ];
                    }
                    // Si no hay snapshot (caso borde), crear un item por monto total
                    if (count($items) === 0) {
                        $items[] = [
                            'monto' => (float)$trx->monto_total,
                            'fecha_cobro' => $today,
                            'order' => 1,
                            'observaciones' => '[QR] alias:' . $alias,
                            'nro_cobro' => null,
                            'pu_mensualidad' => (float)$trx->monto_total,
                            'detalle' => (string)($trx->detalle_glosa ?? 'COBRO QR'),
                            'id_forma_cobro' => (string)$formaCobro,
                        ];
                    }

                    // Obtener datos del cliente desde qr_transacciones (ya guardados en initiate)
                    // y datos del estudiante para nombreEstudiante
                    $clienteData = [
                        'tipo_identidad' => 1,
                        'numero' => '',
                        'razon_social' => '',
                    ];

                    // 1. Datos del cliente desde qr_transacciones (Razón Social y Nro Documento del formulario)
                    try {
                        $tipoId = isset($trx->tipo_identidad_cliente) ? (int)$trx->tipo_identidad_cliente : 1;
                        $numeroDoc = trim((string)($trx->documento_cliente ?? ''));
                        $razonSoc = trim((string)($trx->nombre_cliente ?? ''));

                        $clienteData['tipo_identidad'] = $tipoId;
                        if ($numeroDoc !== '') {
                            $clienteData['numero'] = $numeroDoc;
                        }
                        if ($razonSoc !== '') {
                            $clienteData['razon_social'] = $razonSoc;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('QR callback: error leyendo datos cliente de qr_transacciones', ['err' => $e->getMessage()]);
                    }

                    // 2. Si faltan datos, intentar desde estudiantes como fallback
                    if (empty($clienteData['numero']) || empty($clienteData['razon_social'])) {
                        try {
                            $estudiante = DB::table('estudiantes')->where('cod_ceta', (int)$trx->cod_ceta)->first();
                            if ($estudiante) {
                                if (empty($clienteData['numero'])) {
                                    $ci = trim((string)($estudiante->ci ?? ''));
                                    $clienteData['numero'] = ($ci !== '' && $ci !== '0') ? $ci : (string)$trx->cod_ceta;
                                }
                                if (empty($clienteData['razon_social'])) {
                                    $nombres = trim((string)($estudiante->nombres ?? ''));
                                    $apPaterno = trim((string)($estudiante->ap_paterno ?? ''));
                                    $apMaterno = trim((string)($estudiante->ap_materno ?? ''));
                                    $clienteData['razon_social'] = trim("$nombres $apPaterno $apMaterno");
                                }
                            }
                        } catch (\Throwable $e) {
                            Log::warning('QR callback: error obteniendo fallback desde estudiantes', ['cod_ceta' => (int)$trx->cod_ceta, 'err' => $e->getMessage()]);
                        }
                    }

                    $payload = [
                        'cod_ceta' => (int)$trx->cod_ceta,
                        'cod_pensum' => (string)$trx->cod_pensum,
                        'tipo_inscripcion' => (string)$trx->tipo_inscripcion,
                        'gestion' => (string)($trx->gestion ?? ''),
                        'id_usuario' => (int)$trx->id_usuario,
                        'id_cuentas_bancarias' => (string)$trx->id_cuenta_bancaria,
                        'id_forma_cobro' => (string)$formaCobro,
                        'emitir_online' => true,
                        'qr_context' => true,
                        'items' => $items,
                        'cliente' => $clienteData,
                    ];
                    // Ejecutar batchStore vía contenedor para inyección de dependencias
                    try {
                        Log::info('QR callback: invoking batchStore', [
                            'alias' => $alias,
                            'items' => count($items),
                            'cod_ceta' => (int)$trx->cod_ceta,
                            'forma_cobro' => $formaCobro,
                            'emitir_online' => true,
                            'cliente' => $clienteData,
                        ]);
                    } catch (\Throwable $e) {}
                    $fakeReq = new \Illuminate\Http\Request($payload);
                    $ctrl = app(\App\Http\Controllers\Api\CobroController::class);
                    $response = app()->call([$ctrl, 'batchStore'], ['request' => $fakeReq]);
                    $respJson = method_exists($response, 'getContent') ? json_decode($response->getContent(), true) : null;
                    $ok = is_array($respJson) ? (bool)($respJson['success'] ?? false) : false;
                    try { Log::info('QR callback: batchStore result', ['ok' => $ok, 'resp' => is_array($respJson) ? array_intersect_key($respJson, ['success'=>true,'message'=>true]) : null]); } catch (\Throwable $e) {}

                    DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                        'processed' => $ok ? 1 : 0,
                        'processed_at' => $ok ? now() : null,
                        'saved_by_user' => (bool)($trx->saved_by_user ?? false),
                        'process_error' => $ok ? null : (is_array($respJson) ? json_encode($respJson) : 'batchStore failed'),
                        'updated_at' => now(),
                    ]);
                    $processedNow = $ok ? 1 : 0;

                    // Log de estado de procesamiento
                    DB::table('qr_estados_log')->insert([
                        'id_qr_transaccion' => $trx->id_qr_transaccion,
                        'estado_anterior' => 'completado',
                        'estado_nuevo' => 'completado',
                        'motivo_cambio' => $ok ? 'callback_processed' : 'callback_process_failed',
                        'usuario' => null,
                        'fecha_cambio' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    try {
                        $docTipo = null; $anioDoc = null; $nroDoc = null; $payloadDoc = [];
                        if (is_array($respJson ?? null)) {
                            $itemsResp = $respJson['data']['items'] ?? [];
                            if (is_array($itemsResp)) {
                                foreach ($itemsResp as $it) {
                                    $tipo = isset($it['tipo_documento']) ? (string)$it['tipo_documento'] : '';
                                    $medio = isset($it['medio_doc']) ? (string)$it['medio_doc'] : '';
                                    $fecha = (string)($it['cobro']['fecha_cobro'] ?? date('Y-m-d'));
                                    $anioTry = (int)date('Y', strtotime($fecha));
                                    if ($tipo === 'R' && $medio === 'C' && !empty($it['nro_recibo'] ?? null)) {
                                        $docTipo = 'R'; $anioDoc = $anioTry; $nroDoc = (int)$it['nro_recibo'];
                                        $payloadDoc = ['documento_tipo' => 'R', 'anio_recibo' => $anioDoc, 'nro_recibo' => $nroDoc];
                                        break;
                                    }
                                    if ($tipo === 'F' && $medio === 'C' && !empty($it['nro_factura'] ?? null)) {
                                        $docTipo = 'F'; $anioDoc = $anioTry; $nroDoc = (int)$it['nro_factura'];
                                        $payloadDoc = ['documento_tipo' => 'F', 'anio_factura' => $anioDoc, 'nro_factura' => $nroDoc];
                                        break;
                                    }
                                }
                            }
                        }
                        if ($ok && $docTipo) {
                            $notifier->notifyEvent('documento_generado', array_merge(['id_pago' => $alias], $payloadDoc));
                        } else {
                            $notifier->notifyEvent($ok ? 'factura_generada' : 'procesando_pago', [ 'id_pago' => $alias ]);
                        }
                    } catch (\Throwable $e) {}
                }
            } catch (\Throwable $e) {
                Log::warning('QR callback process failed', ['alias' => $alias, 'err' => $e->getMessage()]);
                try {
                    DB::table('qr_transacciones')->where('id_qr_transaccion', $trx->id_qr_transaccion)->update([
                        'processed' => 0,
                        'processed_at' => null,
                        'process_error' => substr((string)$e->getMessage(), 0, 1000),
                        'updated_at' => now(),
                    ]);
                    $processedNow = 0;
                    DB::table('qr_estados_log')->insert([
                        'id_qr_transaccion' => $trx->id_qr_transaccion,
                        'estado_anterior' => 'completado',
                        'estado_nuevo' => 'completado',
                        'motivo_cambio' => 'callback_exception',
                        'usuario' => null,
                        'fecha_cambio' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $e2) { /* noop */ }
            }
        }

        $notifier->notify(null, [
            'alias' => $alias,
            'status' => $estadoNuevo,
            'cod_ceta' => $trx->cod_ceta,
            'amount' => $trx->monto_total,
        ]);

        try {
            $rowNow = DB::table('qr_transacciones')->select('processed')->where('alias', $alias)->first();
            if (isset($rowNow->processed)) { $processedNow = (int)$rowNow->processed; }
        } catch (\Throwable $e) {}
        return response()->json(['codigo' => '0000', 'mensaje' => 'Registro exitoso', 'meta' => ['alias' => $alias, 'processed' => $processedNow ?? null]]);
    }

    public function disable(Request $request, QrGatewayService $gateway)
    {
        $alias = (string)$request->input('alias');
        if (!$alias) { return response()->json(['success' => false, 'message' => 'alias requerido'], 422); }

        $trx = DB::table('qr_transacciones')->where('alias', $alias)->first();
        if (!$trx) { return response()->json(['success' => false, 'message' => 'Transaction not found'], 404); }
        try {
            $isSaved = (bool)($trx->saved_by_user ?? false);
            $est = (string)($trx->estado ?? '');
            if ($isSaved && !in_array($est, ['completado','cancelado','expirado'], true)) {
                return response()->json(['success' => false, 'message' => 'No se puede anular un QR guardado en espera.'], 422);
            }
        } catch (\Throwable $e) {}
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
        return response()->json(['success' => true, 'data' => ['alias' => $alias, 'estado' => $nuevo, 'payload' => $payload, 'saved_by_user' => (bool)($trx->saved_by_user ?? false)]]);
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
            'saved_by_user' => (bool)($trx->saved_by_user ?? false),
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
