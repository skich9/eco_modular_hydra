<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ParametrosEconomicosController;
use App\Http\Controllers\Api\ItemsCobroController;
use App\Http\Controllers\Api\CarreraController as ApiCarreraController;
use App\Http\Controllers\Api\MateriaController as ApiMateriaController;
use App\Http\Controllers\Api\DescuentoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Rutas de autenticación
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
Route::post('/verify', [\App\Http\Controllers\Api\AuthController::class, 'verify']);

// Ruta de prueba
Route::get('/test', [\App\Http\Controllers\Api\TestController::class, 'test']);

// Recursos: Parámetros Económicos
Route::apiResource('parametros-economicos', ParametrosEconomicosController::class);
Route::patch('parametros-economicos/{id}/toggle-status', [ParametrosEconomicosController::class, 'toggleStatus']);

// Recursos: Items de Cobro
Route::apiResource('items-cobro', ItemsCobroController::class);
Route::patch('items-cobro/{id}/toggle-status', [ItemsCobroController::class, 'toggleStatus']);

// Descuentos
Route::get('descuentos/active', [DescuentoController::class, 'active']);
Route::patch('descuentos/{id}/toggle-status', [DescuentoController::class, 'toggleStatus']);
Route::apiResource('descuentos', DescuentoController::class);

// Carreras
Route::get('carreras', [ApiCarreraController::class, 'index']);
Route::get('carreras/{codigo}/pensums', [ApiCarreraController::class, 'pensums']);

// Materias: endpoints adicionales
Route::get('materias/pensum/{codPensum}', [ApiMateriaController::class, 'getByPensum']);
Route::get('materias/search', [ApiMateriaController::class, 'search']);