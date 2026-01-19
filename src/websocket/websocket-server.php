<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;

// Obtener direccion IP:PUERTO desde el endpoint REST o variable de entorno
$addr = null;
$puerto = null;

// 1. Intentar desde variable de entorno WS_ADDRESS
$addr = getenv('WS_ADDRESS');

// 2. Si no hay WS_ADDRESS, intentar desde endpoint REST
if (!$addr) {
	$base = getenv('APP_URL') ?: 'http://127.0.0.1:8000';
	$endpoint = rtrim($base, '/') . '/api/socket/port';
	try {
		$resp = @file_get_contents($endpoint);
		if ($resp !== false) {
			$data = json_decode($resp, true);
			$addr = (string)($data['port']['valor'] ?? '');
		}
	} catch (\Throwable $e) {}
}

// 3. Si aún no hay addr, usar puerto por defecto
if (!$addr) {
	$addr = '0.0.0.0:8069';
	fwrite(STDERR, "Usando puerto por defecto: $addr\n");
}

if (preg_match('/:(\d+)$/', $addr, $m)) { $puerto = (int)$m[1]; }
if (!$puerto) { fwrite(STDERR, "Valor de puerto inválido en '$addr'\n"); exit(1); }

if (extension_loaded('event') && class_exists('\\Workerman\\Events\\Event')) {
	Worker::$eventLoopClass = '\\Workerman\\Events\\Event';
} else {
	Worker::$eventLoopClass = '\\Workerman\\Events\\Select';
}
$ws = new Worker("websocket://0.0.0.0:$puerto");
$ws->count = 2;

// Log al iniciar cada worker e identificar socket
$ws->onWorkerStart = function($worker) {
    wslog('info', 'WS worker started', [ 'id' => $worker->id ?? null, 'socket' => $worker->socketName ?? null ]);
};

$clientes = [];
$clientesPorPago = [];

function wslog($level, $msg, $ctx = []) {
	$ts = date('Y-m-d H:i:s');
	$ctxStr = $ctx ? (' ' . json_encode($ctx)) : '';
	error_log("[$ts] $level: $msg$ctxStr\n", 3, __DIR__ . '/websocket.log');
}

$ws->onConnect = function($connection) use (&$clientes) {
	$clientes[$connection->id] = $connection;
	wslog('info', 'WS conectado', ['id' => $connection->id]);
};

// Log del handshake inicial y encabezados relevantes
$ws->onWebSocketConnect = function($connection, $http_header = null) {
	$h = [
		'host' => $_SERVER['HTTP_HOST'] ?? null,
		'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
		'upgrade' => $_SERVER['HTTP_UPGRADE'] ?? null,
		'connection' => $_SERVER['HTTP_CONNECTION'] ?? null,
		'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
		'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
	];
	wslog('info', 'WS handshake', ['id' => $connection->id, 'headers' => $h]);
};

$ws->onMessage = function($connection, $data) use (&$clientes, &$clientesPorPago) {
	$payload = json_decode($data, true);
	if (!is_array($payload)) { wslog('error', 'JSON inválido', ['raw' => $data]); return; }
	$ev = (string)($payload['evento'] ?? '');
	switch ($ev) {
		case 'escuchar_factura':
			$idp = (string)($payload['id_pago'] ?? '');
			if ($idp === '') break;
			$connection->id_pago = $idp;
			if (!isset($clientesPorPago[$idp])) { $clientesPorPago[$idp] = []; }
			$clientesPorPago[$idp][$connection->id] = $connection;
			wslog('info', 'escuchar_factura', ['id' => $connection->id, 'id_pago' => $idp]);
			break;
		case 'procesando_pago':
			$idp = (string)($payload['id_pago'] ?? '');
			if (!isset($clientesPorPago[$idp])) break;
			$msg = json_encode(['evento' => 'procesando_pago', 'id_pago' => $idp, 'estado_factura' => 'procesando']);
			foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
			wslog('info', 'broadcast procesando_pago', ['id_pago' => $idp]);
			break;
		case 'factura_generada':
			$idp = (string)($payload['id_pago'] ?? '');
			$msg = json_encode([
				'evento' => 'factura_generada',
				'id_pago' => $idp,
				'nombre_pdf' => $payload['nombre_pdf'] ?? null,
				'documento_tipo' => 'F',
				'anio_factura' => $payload['anio_factura'] ?? null,
				'nro_factura' => $payload['nro_factura'] ?? null,
			]);
			if (isset($clientesPorPago[$idp])) {
				foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
				unset($clientesPorPago[$idp]);
			}
			wslog('info', 'broadcast factura_generada', ['id_pago' => $idp]);
			break;
		case 'recibo_generado':
			$idp = (string)($payload['id_pago'] ?? '');
			$msg = json_encode([
				'evento' => 'recibo_generado',
				'id_pago' => $idp,
				'documento_tipo' => 'R',
				'anio_recibo' => $payload['anio_recibo'] ?? null,
				'nro_recibo' => $payload['nro_recibo'] ?? null,
			]);
			if (isset($clientesPorPago[$idp])) {
				foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
				unset($clientesPorPago[$idp]);
			}
			wslog('info', 'broadcast recibo_generado', ['id_pago' => $idp]);
			break;
		case 'documento_generado':
			$idp = (string)($payload['id_pago'] ?? '');
			$tipo = (string)($payload['documento_tipo'] ?? '');
			if ($tipo === 'R') {
				$msg = json_encode([
					'evento' => 'recibo_generado',
					'id_pago' => $idp,
					'documento_tipo' => 'R',
					'anio_recibo' => $payload['anio_recibo'] ?? null,
					'nro_recibo' => $payload['nro_recibo'] ?? null,
				]);
			} else {
				$msg = json_encode([
					'evento' => 'factura_generada',
					'id_pago' => $idp,
					'documento_tipo' => 'F',
					'anio_factura' => $payload['anio_factura'] ?? null,
					'nro_factura' => $payload['nro_factura'] ?? null,
				]);
			}
			if (isset($clientesPorPago[$idp])) {
				foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
				unset($clientesPorPago[$idp]);
			}
			wslog('info', 'broadcast documento_generado', ['id_pago' => $idp, 'tipo' => $tipo]);
			break;
		case 'status':
			// Compatibilidad: QrSocketNotifier::notify() envía evento 'status' con campos 'alias' y 'status'
			$idp = (string)($payload['alias'] ?? $payload['id_pago'] ?? '');
			if ($idp === '') break;
			$st = strtolower((string)($payload['status'] ?? ''));
			if ($st === 'completado') {
				if (isset($payload['documento_tipo'])) {
					$tipo = (string)$payload['documento_tipo'];
					if ($tipo === 'R') {
						$msg = json_encode([
							'evento' => 'recibo_generado',
							'id_pago' => $idp,
							'documento_tipo' => 'R',
							'anio_recibo' => $payload['anio_recibo'] ?? null,
							'nro_recibo' => $payload['nro_recibo'] ?? null,
						]);
					} else {
						$msg = json_encode([
							'evento' => 'factura_generada',
							'id_pago' => $idp,
							'documento_tipo' => 'F',
							'anio_factura' => $payload['anio_factura'] ?? null,
							'nro_factura' => $payload['nro_factura'] ?? null,
						]);
					}
				} else {
					$msg = json_encode(['evento' => 'factura_generada', 'id_pago' => $idp, 'nombre_pdf' => $payload['nombre_pdf'] ?? null]);
				}
				if (isset($clientesPorPago[$idp])) {
					foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
					unset($clientesPorPago[$idp]);
				}
				wslog('info', 'broadcast status->documento_generado', ['id_pago' => $idp]);
			} elseif ($st === 'procesando' || $st === 'pendiente') {
				if (isset($clientesPorPago[$idp])) {
					$msg = json_encode(['evento' => 'procesando_pago', 'id_pago' => $idp, 'estado_factura' => $st]);
					foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
				}
				wslog('info', 'broadcast status->procesando_pago', ['id_pago' => $idp, 'status' => $st]);
			} elseif ($st === 'cancelado' || $st === 'expirado') {
				// Opcional: notificar estado final no exitoso
				if (isset($clientesPorPago[$idp])) {
					$msg = json_encode(['evento' => 'procesando_pago', 'id_pago' => $idp, 'estado_factura' => $st]);
					foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
					unset($clientesPorPago[$idp]);
				}
				wslog('info', 'broadcast status->final_no_exitoso', ['id_pago' => $idp, 'status' => $st]);
			}
			break;
		case 'prueba_socket':
			$connection->send(json_encode(['evento' => 'confirmacion_prueba', 'mensaje' => 'OK']));
			break;
	}
};

$ws->onClose = function($connection) use (&$clientes, &$clientesPorPago) {
	unset($clientes[$connection->id]);
	if (isset($connection->id_pago) && isset($clientesPorPago[$connection->id_pago][$connection->id])) {
		unset($clientesPorPago[$connection->id_pago][$connection->id]);
		if (empty($clientesPorPago[$connection->id_pago])) { unset($clientesPorPago[$connection->id_pago]); }
	}
	wslog('info', 'WS desconectado', ['id' => $connection->id]);
};

Worker::runAll();
