<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;

// Obtener direccion IP:PUERTO desde el endpoint REST o variable de entorno
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8000';
$endpoint = rtrim($base, '/') . '/api/socket/port';
$addr = getenv('WS_ADDRESS');
$puerto = null;

if (!$addr) {
	try {
		$resp = @file_get_contents($endpoint);
		if ($resp !== false) {
			$data = json_decode($resp, true);
			$addr = (string)($data['port']['valor'] ?? '');
		}
	} catch (\Throwable $e) {}
}

if (!$addr) { fwrite(STDERR, "No se pudo obtener socket/port (usar env WS_ADDRESS o configurar APP_URL).\n"); exit(1); }
if (preg_match('/:(\d+)$/', $addr, $m)) { $puerto = (int)$m[1]; }
if (!$puerto) { fwrite(STDERR, "Valor de puerto inválido en '$addr'\n"); exit(1); }

if (extension_loaded('event') && class_exists('\\Workerman\\Events\\Event')) {
	Worker::$eventLoopClass = '\\Workerman\\Events\\Event';
} else {
	Worker::$eventLoopClass = '\\Workerman\\Events\\Select';
}
$ws = new Worker("websocket://0.0.0.0:$puerto");
$ws->count = 2;

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
			$msg = json_encode(['evento' => 'factura_generada', 'id_pago' => $idp, 'nombre_pdf' => $payload['nombre_pdf'] ?? null]);
			if (isset($clientesPorPago[$idp])) {
				foreach ($clientesPorPago[$idp] as $cli) { $cli->send($msg); }
				unset($clientesPorPago[$idp]);
			}
			wslog('info', 'broadcast factura_generada', ['id_pago' => $idp]);
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
