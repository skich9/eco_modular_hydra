<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\MateriaController;
use App\Http\Controllers\Api\ItemsCobroController;
use App\Http\Controllers\Api\ParametrosEconomicosController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarreraController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\ParametrosSistemaController;

Route::get('/', function () {
    return view('welcome');
});

// Rutas API
Route::prefix('api')->group(function () {
    // Rutas de prueba
    Route::get('/test', [TestController::class, 'test']);
    Route::get('/hello', [TestController::class, 'test']);
    Route::get('/health', function() {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'service' => 'Laravel API'
        ]);
    });
    
    
    // Rutas de Materias
    Route::get('/materias', [MateriaController::class, 'index']);
    Route::post('/materias', [MateriaController::class, 'store']);
    Route::get('/materias/{sigla}/{pensum}', [MateriaController::class, 'show']);
    Route::put('/materias/{sigla}/{pensum}', [MateriaController::class, 'update']);
    Route::delete('/materias/{sigla}/{pensum}', [MateriaController::class, 'destroy']);
    Route::put('/materias/{sigla}/{pensum}/toggle-status', [MateriaController::class, 'toggleStatus']);
    
    // Rutas de Items de Cobro
    Route::get('/items-cobro', [ItemsCobroController::class, 'index']);
    Route::post('/items-cobro', [ItemsCobroController::class, 'store']);
    Route::get('/items-cobro/{id}', [ItemsCobroController::class, 'show']);
    Route::put('/items-cobro/{id}', [ItemsCobroController::class, 'update']);
    Route::delete('/items-cobro/{id}', [ItemsCobroController::class, 'destroy']);
    
    // Rutas de Parámetros Económicos
    Route::get('/parametros-economicos', [ParametrosEconomicosController::class, 'index']);
    Route::post('/parametros-economicos', [ParametrosEconomicosController::class, 'store']);
    Route::get('/parametros-economicos/{id}', [ParametrosEconomicosController::class, 'show']);
    Route::put('/parametros-economicos/{id}', [ParametrosEconomicosController::class, 'update']);
    Route::delete('/parametros-economicos/{id}', [ParametrosEconomicosController::class, 'destroy']);
    
    // Rutas de Usuarios
    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::post('/usuarios', [UsuarioController::class, 'store']);
    Route::get('/usuarios/{id}', [UsuarioController::class, 'show']);
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
    
    // Rutas de Roles
    Route::get('/roles', [RolController::class, 'index']);
    Route::post('/roles', [RolController::class, 'store']);
    Route::get('/roles/{id}', [RolController::class, 'show']);
    Route::put('/roles/{id}', [RolController::class, 'update']);
    Route::delete('/roles/{id}', [RolController::class, 'destroy']);
    
    // Rutas de Parámetros del Sistema
    Route::get('/parametros-sistema', [ParametrosSistemaController::class, 'index']);
    Route::post('/parametros-sistema', [ParametrosSistemaController::class, 'store']);
    Route::get('/parametros-sistema/{id}', [ParametrosSistemaController::class, 'show']);
    Route::put('/parametros-sistema/{id}', [ParametrosSistemaController::class, 'update']);
    Route::delete('/parametros-sistema/{id}', [ParametrosSistemaController::class, 'destroy']);
});
