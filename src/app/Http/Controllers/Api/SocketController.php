<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SocketController extends Controller
{
	public function port(Request $request)
	{
		$addr = (string)config('qr.socket_url'); // esperado: "IP:PUERTO"
		return response()->json([
			'port' => [ 'valor' => $addr ]
		]);
	}
}
