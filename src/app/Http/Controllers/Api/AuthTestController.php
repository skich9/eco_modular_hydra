<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthTestController extends Controller
{
    public function login(Request \)
    {
        \ = \->validate([
            'nickname' => 'required',
            'contrasenia' => 'required',
        ]);

        // Simular autenticación exitosa para pruebas
        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'token' => 'test_token_12345',
            'usuario' => [
                'id' => 1,
                'nombre' => 'Usuario Test',
                'nickname' => \['nickname'],
                'rol' => [
                    'id' => 1,
                    'nombre' => 'admin'
                ]
            ]
        ]);
    }

    public function test()
    {
        return response()->json([
            'message' => '¡API de Laravel funcionando con Angular!',
            'status' => 'success',
            'data' => [
                'framework' => 'Laravel',
                'version' => '12.25.0',
                'time' => date('Y-m-d H:i:s')
            ]
        ]);
    }
}
