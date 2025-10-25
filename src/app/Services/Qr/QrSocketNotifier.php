<?php

namespace App\Services\Qr;

use Illuminate\Support\Facades\Log;

class QrSocketNotifier
{
	public function notify(?string $channel, array $data): void
	{
		$socketUrl = (string)config('qr.socket_url');
		Log::info('QR Socket notify', ['url' => $socketUrl, 'channel' => $channel, 'data' => $data]);
	}
}
