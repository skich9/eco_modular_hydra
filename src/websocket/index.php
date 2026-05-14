<?php

function configured_addr()
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

function normalize_ws_url($addr)
{
    $clean = preg_replace('#^wss?://#i', '', $addr);
    if ($clean === null) {
        $clean = $addr;
    }
    $clean2 = preg_replace('#/.*$#', '', $clean);
    if ($clean2 !== null) {
        $clean = $clean2;
    }
    if ($clean === '' || $clean === '0.0.0.0:8069' || $clean === '0.0.0.0') {
        $clean = '127.0.0.1:8069';
    }
    return 'ws://' . $clean;
}

$configured = configured_addr();
$defaultWs = normalize_ws_url($configured);
$serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'desconocido';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Socket Health</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111a2b;
            --muted: #8ea3c7;
            --text: #e9f1ff;
            --ok: #22c55e;
            --bad: #ef4444;
            --warn: #f59e0b;
            --line: #22324f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Segoe UI, Tahoma, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at 20% 20%, #1a2a46 0%, var(--bg) 50%, #070b15 100%);
            min-height: 100vh;
            padding: 24px;
        }
        .wrap { max-width: 980px; margin: 0 auto; }
        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
            backdrop-filter: blur(4px);
        }
        h1 { margin: 0 0 10px; font-size: 24px; }
        .muted { color: var(--muted); font-size: 14px; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        input, button {
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #0d1628;
            color: var(--text);
            padding: 10px 12px;
        }
        input { min-width: 320px; flex: 1; }
        button { cursor: pointer; }
        button:hover { filter: brightness(1.1); }
        .tag {
            padding: 4px 10px;
            border-radius: 999px;
            display: inline-block;
            border: 1px solid var(--line);
            font-size: 12px;
        }
        .ok { color: var(--ok); border-color: rgba(34,197,94,0.4); }
        .bad { color: var(--bad); border-color: rgba(239,68,68,0.4); }
        .warn { color: var(--warn); border-color: rgba(245,158,11,0.4); }
        pre {
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
            font-size: 12px;
            background: #09101d;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            max-height: 350px;
            overflow: auto;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Diagnostico de WebSocket</h1>
        <div class="muted">Servidor: <?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">QR_SOCKET_URL/WS_ADDRESS detectado: <strong><?= htmlspecialchars($configured, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div class="muted">URL sugerida para prueba: <strong id="suggested"><?= htmlspecialchars($defaultWs, ENT_QUOTES, 'UTF-8') ?></strong></div>
    </div>

    <div class="card">
        <div class="row" style="margin-bottom: 10px;">
            <input id="wsUrl" value="<?= htmlspecialchars($defaultWs, ENT_QUOTES, 'UTF-8') ?>" />
            <button id="btnConnect">Conectar</button>
            <button id="btnClose">Cerrar</button>
            <button id="btnPing">Ping prueba_socket</button>
        </div>
        <div class="row">
            <span id="wsStatus" class="tag warn">Sin conexion</span>
            <span id="tcpStatus" class="tag warn">TCP sin verificar</span>
        </div>
    </div>

    <div class="card">
        <div class="row" style="margin-bottom: 8px;">
            <button id="btnCheckTcp">Verificar TCP (health.php)</button>
            <button id="btnClear">Limpiar log</button>
        </div>
        <pre id="log"></pre>
    </div>
</div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const logEl = $('log');
    const wsStatus = $('wsStatus');
    const tcpStatus = $('tcpStatus');
    const input = $('wsUrl');
    let ws = null;

    function log(msg, obj) {
        const ts = new Date().toISOString();
        const line = obj !== undefined ? `${ts} ${msg} ${JSON.stringify(obj)}` : `${ts} ${msg}`;
        logEl.textContent += (line + '\n');
        logEl.scrollTop = logEl.scrollHeight;
    }

    function setWs(text, cls) {
        wsStatus.textContent = text;
        wsStatus.className = `tag ${cls}`;
    }

    function setTcp(text, cls) {
        tcpStatus.textContent = text;
        tcpStatus.className = `tag ${cls}`;
    }

    async function checkTcp() {
        try {
            const current = encodeURIComponent((input.value || '').trim());
            const res = await fetch(`./health.php?target=${current}`, { cache: 'no-store' });
            const data = await res.json();
            setTcp(data.ok ? 'TCP activo' : 'TCP caido', data.ok ? 'ok' : 'bad');
            log('health', data);
        } catch (e) {
            setTcp('TCP error', 'bad');
            log('health error', { error: String(e) });
        }
    }

    function connect() {
        const url = (input.value || '').trim();
        if (!url) return;
        try {
            ws = new WebSocket(url);
            setWs('Conectando...', 'warn');

            ws.onopen = () => {
                setWs('Conectado', 'ok');
                log('ws open', { url });
            };

            ws.onmessage = (ev) => {
                let payload = ev.data;
                try { payload = JSON.parse(ev.data); } catch (_) {}
                log('ws message', payload);
            };

            ws.onerror = (ev) => {
                setWs('Error', 'bad');
                log('ws error', { type: ev.type });
            };

            ws.onclose = (ev) => {
                setWs('Cerrado', 'warn');
                log('ws close', { code: ev.code, reason: ev.reason });
            };
        } catch (e) {
            setWs('Error al crear WS', 'bad');
            log('ws create error', { error: String(e) });
        }
    }

    function closeWs() {
        if (ws) {
            ws.close();
            ws = null;
        }
    }

    function ping() {
        if (!ws || ws.readyState !== WebSocket.OPEN) {
            log('ping', { error: 'WS no conectado' });
            return;
        }
        const payload = { evento: 'prueba_socket' };
        ws.send(JSON.stringify(payload));
        log('ws send', payload);
    }

    $('btnConnect').addEventListener('click', connect);
    $('btnClose').addEventListener('click', closeWs);
    $('btnPing').addEventListener('click', ping);
    $('btnCheckTcp').addEventListener('click', checkTcp);
    $('btnClear').addEventListener('click', () => { logEl.textContent = ''; });

    checkTcp();
})();
</script>
</body>
</html>
