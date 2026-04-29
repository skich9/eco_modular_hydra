<?php

header('Content-Type: application/json; charset=utf-8');

function ws_addr_raw()
{
    $addr = getenv('QR_SOCKET_URL');
    if (!$addr) {
        $addr = getenv('WS_ADDRESS');
    }
    if (!$addr) {
        $addr = '127.0.0.1:8069';
    }
    return trim((string)$addr);
}

function parse_host_port($addr)
{
    $clean = preg_replace('#^wss?://#i', '', $addr);
    if ($clean === null) {
        $clean = $addr;
    }
    $clean2 = preg_replace('#/.*$#', '', $clean);
    if ($clean2 !== null) {
        $clean = $clean2;
    }

    $host = $clean;
    $port = 8069;

    if (strpos($clean, ':') !== false) {
        $parts = explode(':', $clean);
        $portRaw = array_pop($parts);
        $host = implode(':', $parts);
        if (is_numeric($portRaw)) {
            $port = (int)$portRaw;
        }
    }

    if ($host === '' || $host === '0.0.0.0') {
        $host = '127.0.0.1';
    }

    return array($host, $port);
}

$raw = ws_addr_raw();
$hp = parse_host_port($raw);
$host = $hp[0];
$port = $hp[1];

$errno = 0;
$errstr = '';
$t0 = microtime(true);
$conn = @fsockopen($host, $port, $errno, $errstr, 1.5);
$ms = (int)round((microtime(true) - $t0) * 1000);

$ok = is_resource($conn);
if ($ok && is_resource($conn)) {
    fclose($conn);
}

$out = array(
    'ok' => $ok,
    'timestamp' => date('c'),
    'source' => 'env/default',
    'configured' => array(
        'raw' => $raw,
        'host' => $host,
        'port' => $port,
        'ws_url' => 'ws://' . $host . ':' . $port,
    ),
    'probe' => array(
        'latency_ms' => $ms,
        'errno' => $ok ? null : $errno,
        'error' => $ok ? null : $errstr,
    ),
);

$flags = 0;
if (defined('JSON_UNESCAPED_SLASHES')) {
    $flags |= JSON_UNESCAPED_SLASHES;
}
if (defined('JSON_UNESCAPED_UNICODE')) {
    $flags |= JSON_UNESCAPED_UNICODE;
}
if (defined('JSON_PRETTY_PRINT')) {
    $flags |= JSON_PRETTY_PRINT;
}

echo json_encode($out, $flags);
