<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ParametrosEconomicosController;
use App\Http\Controllers\Api\ItemsCobroController;
use App\Http\Controllers\Api\CarreraController as ApiCarreraController;
use App\Http\Controllers\Api\MateriaController as ApiMateriaController;
use App\Http\Controllers\CostoMateriaController;
use App\Http\Controllers\GestionController;

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

// Carreras
Route::get('carreras', [ApiCarreraController::class, 'index']);
Route::get('carreras/{codigo}/pensums', [ApiCarreraController::class, 'pensums']);

// Materias
Route::get('materias', [ApiMateriaController::class, 'index']);
Route::post('materias', [ApiMateriaController::class, 'store']);
// Materias: endpoints adicionales (más específicos primero)
Route::get('materias/pensum/{codPensum}', [ApiMateriaController::class, 'getByPensum']);
Route::get('materias/search', [ApiMateriaController::class, 'search']);
// Materias: endpoints por clave compuesta (colocadas después para evitar colisiones)
Route::get('materias/{sigla}/{pensum}', [ApiMateriaController::class, 'show']);
Route::put('materias/{sigla}/{pensum}', [ApiMateriaController::class, 'update']);
Route::delete('materias/{sigla}/{pensum}', [ApiMateriaController::class, 'destroy']);
Route::put('materias/{sigla}/{pensum}/toggle-status', [ApiMateriaController::class, 'toggleStatus']);

//Costo Materia
Route::apiResource('costo-materia', CostoMateriaController::class);
Route::get('costo-materia/gestion/{gestion}/materia/{siglaMateria}', [CostoMateriaController::class, 'getByGestionAndMateria'])->where('gestion', '.*');
Route::get('costo-materia/gestion/{gestion}', [CostoMateriaController::class, 'getByGestion'])->where('gestion', '.*');

//Gestión
// Rutas para gestión
Route::apiResource('gestiones', GestionController::class);
Route::get('gestiones/actual/actual', [GestionController::class, 'gestionActual']);
Route::get('gestiones/estado/activas', [GestionController::class, 'gestionesActivas']);
Route::get('gestiones/ano/{anio}', [GestionController::class, 'porAnio']);
Route::patch('gestiones/{gestion}/estado', [GestionController::class, 'cambiarEstado']);