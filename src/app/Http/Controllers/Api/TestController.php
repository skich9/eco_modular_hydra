<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function test(): JsonResponse
    {
        return response()->json([
            'message' => '¡API de Laravel funcionando con Angular!',
            'status' => 'success',
            'data' => [
                'framework' => 'Laravel',
                'version' => app()->version(),
                'time' => now()->toDateTimeString()
            ]
        ]);
    }
}