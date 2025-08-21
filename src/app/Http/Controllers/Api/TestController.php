<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestController extends Controller
{
    /**
     * Método de prueba para la API
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function test()
    {
        return response()->json([
            'message' => '¡API de Laravel funcionando con Angular!',
            'status' => 'success',
            'data' => [
                'framework' => 'Laravel',
                'version' => app()->version(),
                'time' => now()->format('Y-m-d H:i:s')
            ]
        ]);
    }
}
