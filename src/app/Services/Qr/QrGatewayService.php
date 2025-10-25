<?php

namespace App\Services\Qr;

use Illuminate\Support\Facades\Http;

class QrGatewayService
{
    public function authenticate(): array
    {
        $url = config('qr.url_auth');
        $key = config('qr.api_key');
        $payload = [
            'password' => config('qr.password'),
            'username' => config('qr.username'),
        ];
        if (!$url || !$key) { return ['ok' => false, 'token' => null]; }

        $http = Http::withHeaders(['apikey' => $key])
            ->timeout((int)config('qr.http_timeout', 30))
            ->connectTimeout((int)config('qr.http_connect_timeout', 15));

        $options = [];
        if (!config('qr.http_verify_ssl', true)) { $options['verify'] = false; }
        if (config('qr.http_force_ipv4', false)) { $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; }
        if (config('qr.http_proxy')) { $options['proxy'] = config('qr.http_proxy'); }
        if (!empty($options)) { $http = $http->withOptions($options); }

        $resp = $http->post($url, $payload);
        if (!$resp->ok()) { return ['ok' => false, 'token' => null]; }
        $data = $resp->json();
        $token = $data['objeto']['token'] ?? null;
        return ['ok' => (bool)$token, 'token' => $token];
    }

    public function createPayment(string $token, array $data, ?string $apikeyServicio = null): array
    {
        $url = rtrim((string)config('qr.url_transfer'), '/') . '/api/v1/generaQr';
        $key = $apikeyServicio ?: config('qr.api_key_servicio');
        if (!$url || !$key || !$token) { return ['ok' => false, 'error' => 'missing_config']; }

        $http = Http::withHeaders([
            'apikeyServicio' => $key,
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->timeout((int)config('qr.http_timeout', 30))
          ->connectTimeout((int)config('qr.http_connect_timeout', 15));

        $options = [];
        if (!config('qr.http_verify_ssl', true)) { $options['verify'] = false; }
        if (config('qr.http_force_ipv4', false)) { $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; }
        if (config('qr.http_proxy')) { $options['proxy'] = config('qr.http_proxy'); }
        if (!empty($options)) { $http = $http->withOptions($options); }

        $resp = $http->post($url, $data);
        if (!$resp->ok()) { return ['ok' => false, 'status' => $resp->status(), 'body' => $resp->body()]; }
        $j = $resp->json();
        return ['ok' => true, 'data' => $j];
    }

    public function disablePayment(string $token, string $alias): array
    {
        $url = rtrim((string)config('qr.url_transfer'), '/') . '/api/v1/inhabilitarPago';
        $key = config('qr.api_key_servicio');
        if (!$url || !$key || !$token) { return ['ok' => false, 'error' => 'missing_config']; }

        $http = Http::withHeaders([
            'apikeyServicio' => $key,
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->timeout((int)config('qr.http_timeout', 30))
          ->connectTimeout((int)config('qr.http_connect_timeout', 15));

        $options = [];
        if (!config('qr.http_verify_ssl', true)) { $options['verify'] = false; }
        if (config('qr.http_force_ipv4', false)) { $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; }
        if (config('qr.http_proxy')) { $options['proxy'] = config('qr.http_proxy'); }
        if (!empty($options)) { $http = $http->withOptions($options); }

        $resp = $http->post($url, ['alias' => $alias]);
        if (!$resp->ok()) { return ['ok' => false, 'status' => $resp->status(), 'body' => $resp->body()]; }
        $j = $resp->json();
        $code = $j['codigo'] ?? null;
        return ['ok' => in_array($code, ['0000','OK'], true), 'data' => $j];
    }

    public function getStatus(string $token, string $alias): array
    {
        $url = rtrim((string)config('qr.url_transfer'), '/') . '/api/v1/estadoTransaccion';
        $key = config('qr.api_key_servicio');
        if (!$url || !$key || !$token) { return ['ok' => false, 'error' => 'missing_config']; }

        $http = Http::withHeaders([
            'apikeyServicio' => $key,
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->timeout((int)config('qr.http_timeout', 30))
          ->connectTimeout((int)config('qr.http_connect_timeout', 15));

        $options = [];
        if (!config('qr.http_verify_ssl', true)) { $options['verify'] = false; }
        if (config('qr.http_force_ipv4', false)) { $options['curl'][CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; }
        if (config('qr.http_proxy')) { $options['proxy'] = config('qr.http_proxy'); }
        if (!empty($options)) { $http = $http->withOptions($options); }

        $resp = $http->post($url, ['alias' => $alias]);
        if (!$resp->ok()) { return ['ok' => false, 'status' => $resp->status(), 'body' => $resp->body()]; }
        $j = $resp->json();
        $code = $j['codigo'] ?? null;
        return ['ok' => in_array($code, ['0000','OK'], true), 'data' => $j];
    }
}
