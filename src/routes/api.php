<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Api\EstudianteController;
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
use App\Http\Controllers\Api\ReciboController;
use App\Http\Controllers\Api\SinActividadController;
use App\Http\Controllers\Api\SinCatalogoController;
use App\Http\Controllers\Api\ParametroCostoController;
use App\Http\Controllers\Api\ParametroCuotaController;
use App\Http\Controllers\Api\CostoSemestralController;
use App\Http\Controllers\Api\CuotaController;
use App\Http\Controllers\Api\InscripcionesWebhookController;
use App\Http\Controllers\Api\KardexNotasController;
use App\Http\Controllers\Api\RezagadoController;
use App\Http\Controllers\Api\SegundaInstanciaController;
use App\Http\Controllers\Api\QrController;

// Búsqueda de estudiantes
Route::get('/estudiantes/search', [EstudianteController::class, 'search']);

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

// ===================== SGA Proxy (Reincorporación) =====================
Route::match(['get','post'], 'sga/eco_hydra/Reincorporacion/estado', function (Request $request) {
    $base = env('SGA_BASE_URL');
    try {
        if ($base) {
            $url = rtrim($base, '/') . '/eco_hydra/Reincorporacion/estado';
            $req = Http::timeout(10);
            $payload = [
                'cod_ceta' => $request->input('cod_ceta', $request->query('cod_ceta')),
                'cod_pensum' => $request->input('cod_pensum', $request->query('cod_pensum')),
                'gestion' => $request->input('gestion', $request->query('gestion')),
            ];
            $resp = ($request->method() === 'POST') ? $req->post($url, $payload) : $req->get($url, $payload);
            if ($resp->ok()) { return response()->json($resp->json(), 200); }
            return response()->json($resp->json(), $resp->status());
        }
    } catch (\Throwable $e) {
        // fallthrough al fallback
    }
    return response()->json([
        'success' => true,
        'data' => [
            'parametros' => [ 'activo' => false, 'semestres_requeridos' => 0 ],
            'ultima_gestion' => null,
            'gestiones_activas' => [],
            'gestiones_abandonadas' => [],
            'debe_reincorporacion_sql' => false,
            'estudiante_nuevo_1er_semestre_normal' => false,
            'debe_reincorporacion' => false,
        ],
        'message' => 'SGA_BASE_URL no configurado o SGA no disponible. Respuesta local por defecto.'
    ], 200);
});

// Rutas de autenticación
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
Route::post('/verify', [\App\Http\Controllers\Api\AuthController::class, 'verify']);
Route::post('/change-password', [\App\Http\Controllers\Api\AuthController::class, 'changePassword']);

// Ruta de prueba
Route::get('/test', [\App\Http\Controllers\Api\TestController::class, 'test']);
// Health check
Route::get('/health', function() { return response()->json(['status' => 'ok']); });

// Recursos: Parámetros Económicos
Route::apiResource('parametros-economicos', ParametrosEconomicosController::class);
Route::patch('parametros-economicos/{id}/toggle-status', [ParametrosEconomicosController::class, 'toggleStatus']);

// Recursos: Items de Cobro
Route::apiResource('items-cobro', ItemsCobroController::class);
Route::patch('items-cobro/{id}/toggle-status', [ItemsCobroController::class, 'toggleStatus']);
// Sincronización desde SGA/SIN
Route::post('items-cobro/sync-sin', [ItemsCobroController::class, 'syncFromSin']);

// Formas de cobro (catálogo)
Route::get('formas-cobro', [FormaCobroController::class, 'index']);

// Cuentas bancarias (catálogo)
Route::get('cuentas-bancarias', [CuentaBancariaController::class, 'index']);

// Actividades económicas (SIN)
Route::get('sin-actividades', [SinActividadController::class, 'index']);
// Documentos de identidad (SIN)
Route::get('sin/documentos-identidad', [SinCatalogoController::class, 'documentosIdentidad']);

// SIN Admin (S1, S2)
Route::post('sin/sync/all', [\App\Http\Controllers\Api\SinAdminController::class, 'syncAll']);
Route::post('sin/sync/leyendas', [\App\Http\Controllers\Api\SinAdminController::class, 'syncLeyendas']);
Route::post('sin/sync/metodo-pago', [\App\Http\Controllers\Api\SinAdminController::class, 'syncMetodoPago']);
Route::get('sin/status', [\App\Http\Controllers\Api\SinAdminController::class, 'status']);

// Cobros (clave compuesta)
Route::get('cobros', [CobroController::class, 'index']);
Route::post('cobros', [CobroController::class, 'store']);
// Cobros: endpoints adicionales
Route::get('cobros/resumen', [CobroController::class, 'resumen']);
Route::post('cobros/batch', [CobroController::class, 'batchStore']);
Route::post('cobros/validar-impuestos', [CobroController::class, 'validarImpuestos']);
Route::get('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'show']);
Route::put('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'update']);
Route::delete('cobros/{cod_ceta}/{cod_pensum}/{tipo_inscripcion}/{nro_cobro}', [CobroController::class, 'destroy']);

// Recibos: PDF
Route::get('recibos/{anio}/{nro_recibo}/pdf', [ReciboController::class, 'pdf'])
    ->where(['anio' => '\\d{4}', 'nro_recibo' => '\\d+']);

// Parámetros de costos
Route::get('parametros-costos', [ParametroCostoController::class, 'index']);
Route::get('parametros-costos/activos', [ParametroCostoController::class, 'activos']);
Route::post('parametros-costos', [ParametroCostoController::class, 'store']);
Route::put('parametros-costos/{id}', [ParametroCostoController::class, 'update']);

// Parámetros de cuotas
Route::get('parametros-cuota', [ParametroCuotaController::class, 'index']);
Route::get('parametros-cuota/activos', [ParametroCuotaController::class, 'activos']);
Route::post('parametros-cuota', [ParametroCuotaController::class, 'store']);
Route::put('parametros-cuota/{id}', [ParametroCuotaController::class, 'update']);

// Costo semestral por pensum (gestion opcional)
Route::get('costo-semestral/pensum/{codPensum}', [CostoSemestralController::class, 'byPensum']);
Route::post('costo-semestral/batch', [CostoSemestralController::class, 'batchStore']);
Route::put('costo-semestral/{id}', [CostoSemestralController::class, 'update']);
Route::delete('costo-semestral/{id}', [CostoSemestralController::class, 'destroy']);

// Cuotas (creación en lote desde asignación de costos)
Route::get('cuotas', [CuotaController::class, 'index']);
Route::post('cuotas/batch', [CuotaController::class, 'batchStore']);
Route::put('cuotas/context', [CuotaController::class, 'updateByContext']);
Route::post('cuotas/context/delete', [CuotaController::class, 'deleteByContext']);

// Webhooks desde SGA (inscripciones creadas)
Route::post('webhooks/inscripciones/created', [InscripcionesWebhookController::class, 'created']);

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
Route::post('materias/credits/batch', [ApiMateriaController::class, 'batchUpdateCredits']);
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
Route::post('costo-materia/batch', [CostoMateriaController::class, 'batchUpsert']);
Route::post('costo-materia/generate', [CostoMateriaController::class, 'generateByPensumGestion']);

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

// ===================== Kardex Notas =====================
Route::get('kardex-notas/materias', [KardexNotasController::class, 'materias']);

// ===================== SGA Proxy (Recuperación) =====================
// Proxy simple para evitar 404 en frontend y centralizar CORS en Laravel
Route::get('sga/eco_hydra/Recuperacion/elegibilidad', function (Request $request) {
    $base = env('SGA_BASE_URL');
    try {
        if ($base) {
            $url = rtrim($base, '/') . '/eco_hydra/Recuperacion/elegibilidad';
            $resp = Http::timeout(8)->get($url, [
                'cod_ceta' => $request->query('cod_ceta'),
                'cod_pensum' => $request->query('cod_pensum'),
                'gestion' => $request->query('gestion'),
            ]);
            if ($resp->ok()) {
                return response()->json($resp->json(), 200);
            }
            return response()->json($resp->json(), $resp->status());
        }
    } catch (\Throwable $e) {
        // fallthrough al fallback
    }
    // Fallback amistoso para no romper la UI si SGA no está disponible
    return response()->json([
        'success' => true,
        'data' => [
            'elegible' => true,
            'motivo' => null,
            'materias' => [],
        ],
        'message' => 'SGA_BASE_URL no configurado o SGA no disponible. Respuesta local por defecto.'
    ], 200);
});

Route::get('sga/eco_hydra/Recuperacion/autorizaciones', function (Request $request) {
    $base = env('SGA_BASE_URL');
    try {
        if ($base) {
            $url = rtrim($base, '/') . '/eco_hydra/Recuperacion/autorizaciones';
            $req = Http::timeout(8);
            $cookie = env('SGA_SESSION_COOKIE');
            if ($cookie) { $req = $req->withHeaders(['Cookie' => $cookie]); }
            $resp = $req->get($url, [
                'cod_ceta' => $request->query('cod_ceta'),
                'cod_pensum' => $request->query('cod_pensum'),
            ]);
            if ($resp->ok()) {
                return response()->json($resp->json(), 200);
            }
            return response()->json($resp->json(), $resp->status());
        }
    } catch (\Throwable $e) {
        // fallthrough al fallback
    }
    return response()->json([
        'success' => true,
        'data' => [],
        'message' => 'SGA_BASE_URL no configurado o SGA no disponible. Respuesta local por defecto.'
    ], 200);
});

// ===================== Roles =====================
Route::get('roles/active', [RolController::class, 'rolesActivos']);
Route::apiResource('roles', RolController::class);
Route::patch('roles/{id}/toggle-status', [RolController::class, 'cambiarEstado']);

// ===================== Razón Social =====================
Route::get('razon-social/search', [RazonSocialController::class, 'search']);
Route::post('razon-social', [RazonSocialController::class, 'store']);

// ===================== Rezagados =====================
Route::get('rezagados', [RezagadoController::class, 'index']);
Route::post('rezagados', [RezagadoController::class, 'store']);
Route::get('rezagados/{cod_inscrip}/{num_rezagado}/{num_pago_rezagado}', [RezagadoController::class, 'show'])
    ->where(['cod_inscrip' => '\\d+', 'num_rezagado' => '\\d+', 'num_pago_rezagado' => '\\d+']);
Route::put('rezagados/{cod_inscrip}/{num_rezagado}/{num_pago_rezagado}', [RezagadoController::class, 'update'])
    ->where(['cod_inscrip' => '\\d+', 'num_rezagado' => '\\d+', 'num_pago_rezagado' => '\\d+']);
Route::delete('rezagados/{cod_inscrip}/{num_rezagado}/{num_pago_rezagado}', [RezagadoController::class, 'destroy'])
    ->where(['cod_inscrip' => '\\d+', 'num_rezagado' => '\\d+', 'num_pago_rezagado' => '\\d+']);

// ===================== Segunda Instancia =====================
Route::get('segunda-instancia', [SegundaInstanciaController::class, 'index']);
Route::post('segunda-instancia', [SegundaInstanciaController::class, 'store']);
Route::get('segunda-instancia/elegibilidad', [SegundaInstanciaController::class, 'elegibilidad']);

// ===================== Pagos QR =====================
Route::post('qr/initiate', [QrController::class, 'initiate']);
Route::post('qr/callback', [QrController::class, 'callback']);
Route::post('qr/disable', [QrController::class, 'disable']);
Route::post('qr/status', [QrController::class, 'status']);