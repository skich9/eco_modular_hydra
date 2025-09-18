<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ParametrosEconomicosController;
use App\Http\Controllers\Api\ItemsCobroController;
use App\Http\Controllers\Api\CobroController;
use App\Http\Controllers\Api\CarreraController as ApiCarreraController;
use App\Http\Controllers\Api\MateriaController as ApiMateriaController;
use App\Http\Controllers\CostoMateriaController;
use App\Http\Controllers\GestionController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\Api\DescuentoController;
use App\Http\Controllers\Api\DefDescuentoController;
use App\Http\Controllers\Api\DefDescuentoBecaController;
use App\Http\Controllers\Api\ParametroGeneralController;
use App\Http\Controllers\Api\FormaCobroController;
use App\Http\Controllers\Api\RazonSocialController;
use App\Http\Controllers\Api\CuentaBancariaController;
use App\Http\Controllers\Api\SinActividadController;
use App\Http\Controllers\Api\SinCatalogoController;
use App\Http\Controllers\Api\ParametroCostoController;

/*
--------------------------------------------------------------------------
| API Routes
--------------------------------------------------------------------------
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
Route::post('/change-password', [\App\Http\Controllers\Api\AuthController::class, 'changePassword']);

// Ruta de prueba
Route::get('/test', [\App\Http\Controllers\Api\TestController::class, 'test']);

// Recursos: Parámetros Económicos
Route::apiResource('parametros-economicos', ParametrosEconomicosController::class);
Route::patch('parametros-economicos/{id}/toggle-status', [ParametrosEconomicosController::class, 'toggleStatus']);

// Recursos: Items de Cobro
Route::apiResource('items-cobro', ItemsCobroController::class);
Route::patch('items-cobro/{id}/toggle-status', [ItemsCobroController::class, 'toggleStatus']);

// Formas de cobro (catálogo)
Route::get('formas-cobro', [FormaCobroController::class, 'index']);

// Cuentas bancarias (catálogo)
Route::get('cuentas-bancarias', [CuentaBancariaController::class, 'index']);

// Actividades económicas (SIN)
Route::get('sin-actividades', [SinActividadController::class, 'index']);
// Documentos de identidad (SIN)
Route::get('sin/documentos-identidad', [SinCatalogoController::class, 'documentosIdentidad']);

// Cobros (clave compuesta)
Route::get('cobros', [CobroController::class, 'index']);
Route::post('cobros', [CobroController::class, 'store']);
// Cobros: endpoints adicionales
Route::get('cobros/resumen', [CobroController::class, 'resumen']);
Route::post('cobros/batch', [CobroController::class, 'batchStore']);
Route::get('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'show']);
Route::put('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'update']);
Route::delete('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'destroy']);

// Parámetros de costos (activos)
Route::get('parametros-costos/activos', [ParametroCostoController::class, 'activos']);

// Descuentos
Route::get('descuentos/active', [DescuentoController::class, 'active']);
Route::patch('descuentos/{id}/toggle-status', [DescuentoController::class, 'toggleStatus']);
Route::apiResource('descuentos', DescuentoController::class);

// Definiciones de descuentos
Route::apiResource('def-descuentos', DefDescuentoController::class);
Route::patch('def-descuentos/{id}/toggle-status', [DefDescuentoController::class, 'toggleStatus']);
Route::apiResource('def-descuentos-beca', DefDescuentoBecaController::class);
Route::patch('def-descuentos-beca/{id}/toggle-status', [DefDescuentoBecaController::class, 'toggleStatus']);

// Parámetros generales
Route::apiResource('parametros-generales', ParametroGeneralController::class);
Route::patch('parametros-generales/{id}/toggle-status', [ParametroGeneralController::class, 'toggleStatus']);

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
Route::apiResource('gestiones', GestionController::class)->only(['index', 'store']);
// Rutas específicas (primero)
Route::get('gestiones/actual/actual', [GestionController::class, 'gestionActual']);
Route::get('gestiones/estado/activas', [GestionController::class, 'gestionesActivas']);
Route::get('gestiones/ano/{anio}', [GestionController::class, 'porAnio']);
Route::patch('gestiones/{gestion}/estado', [GestionController::class, 'cambiarEstado'])->where('gestion', '.*');
// Rutas con parámetro catch-all (después)
Route::get('gestiones/{gestion}', [GestionController::class, 'show'])->where('gestion', '.*');
Route::put('gestiones/{gestion}', [GestionController::class, 'update'])->where('gestion', '.*');
Route::delete('gestiones/{gestion}', [GestionController::class, 'destroy'])->where('gestion', '.*');

// ===================== Usuarios =====================
Route::apiResource('usuarios', UsuarioController::class);
Route::get('usuarios/search', [UsuarioController::class, 'search']);
Route::get('usuarios/rol/{idRol}', [UsuarioController::class, 'usuariosPorRol']);
Route::patch('usuarios/{id}/toggle-status', [UsuarioController::class, 'cambiarEstado']);
Route::post('usuarios/{id}/reset-password', [UsuarioController::class, 'resetPassword']);

// ===================== Roles =====================
Route::get('roles/active', [RolController::class, 'rolesActivos']);
Route::apiResource('roles', RolController::class);
Route::patch('roles/{id}/toggle-status', [RolController::class, 'cambiarEstado']);

// ===================== Razón Social =====================
Route::get('razon-social/search', [RazonSocialController::class, 'search']);
Route::post('razon-social', [RazonSocialController::class, 'store']);