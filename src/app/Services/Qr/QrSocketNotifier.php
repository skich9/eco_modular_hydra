<?php

namespace App\Services\Qr;

use Illuminate\Support\Facades\Log;
use Throwable;

class QrSocketNotifier
{
    public function notify($channel, $data)
    {
        // Compatibilidad hacia atrás: emite un evento genérico 'status'
        $payload = array_merge(['evento' => 'status'], $data);
        $this->notifyEvent($payload['evento'], $payload);
    }

    public function notifyEvent($event, $data)
    {
        $addr = (string) config('qr.socket_url'); // esperado: "IP:PUERTO"
        if ($addr === '') {
            Log::info('QR Socket notify skipped (no socket_url)', ['evento' => $event, 'data' => $data]);
            return;
        }
        $wsUrl = (str_starts_with($addr, 'ws://') || str_starts_with($addr, 'wss://')) ? $addr : ('ws://' . $addr);
        $payload = array_merge(['evento' => $event], $data);
        $json = json_encode($payload);
        try {
            if (class_exists('WebSocket\\Client')) {
                $client = new \WebSocket\Client($wsUrl, [ 'timeout' => 3 ]);
                $client->send($json);
                try { $client->close(); } catch (Throwable $e) {}
                Log::info('QR Socket sent', ['url' => $wsUrl, 'payload' => $payload]);
            } else {
                Log::info('QR Socket lib not installed, log only', ['url' => $wsUrl, 'payload' => $payload]);
            }
        } catch (Throwable $e) {
            Log::warning('QR Socket send failed', ['url' => $wsUrl, 'payload' => $payload, 'err' => $e->getMessage()]);
        }
    }
}
