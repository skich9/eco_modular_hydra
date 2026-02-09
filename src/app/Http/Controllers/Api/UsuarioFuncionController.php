<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\Funcion;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UsuarioFuncionController extends Controller
{
	protected $permissionService;

	public function __construct(PermissionService $permissionService)
	{
		$this->permissionService = $permissionService;
	}

	public function index($usuarioId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$funciones = $this->permissionService->getUserFunctions($usuarioId);

		return response()->json([
			'success' => true,
			'data' => $funciones
		]);
	}

	public function byModule($usuarioId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$funciones = $this->permissionService->getUserFunctionsByModule($usuarioId);

		return response()->json([
			'success' => true,
			'data' => $funciones
		]);
	}

	public function store(Request $request, $usuarioId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$validator = Validator::make($request->all(), [
			'id_funcion' => 'required|exists:funciones,id_funcion',
			'fecha_ini' => 'nullable|date',
			'fecha_fin' => 'nullable|date|after_or_equal:fecha_ini',
			'observaciones' => 'nullable|string'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$asignadoPor = $request->user()->id_usuario ?? null;

		$this->permissionService->assignFunction(
			$usuarioId,
			$request->id_funcion,
			$request->fecha_ini,
			$request->fecha_fin,
			$request->observaciones,
			$asignadoPor
		);

		return response()->json([
			'success' => true,
			'message' => 'Función asignada exitosamente'
		], 201);
	}

	public function update(Request $request, $usuarioId, $funcionId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$validator = Validator::make($request->all(), [
			'fecha_ini' => 'nullable|date',
			'fecha_fin' => 'nullable|date|after_or_equal:fecha_ini',
			'observaciones' => 'nullable|string'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$asignadoPor = $request->user()->id_usuario ?? null;

		$this->permissionService->assignFunction(
			$usuarioId,
			$funcionId,
			$request->fecha_ini,
			$request->fecha_fin,
			$request->observaciones,
			$asignadoPor
		);

		return response()->json([
			'success' => true,
			'message' => 'Función actualizada exitosamente'
		]);
	}

	public function destroy($usuarioId, $funcionId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$this->permissionService->removeFunction($usuarioId, $funcionId);

		return response()->json([
			'success' => true,
			'message' => 'Función removida exitosamente'
		]);
	}

	public function copyFromRole(Request $request, $usuarioId)
	{
		$usuario = Usuario::find($usuarioId);

		if (!$usuario) {
			return response()->json([
				'success' => false,
				'message' => 'Usuario no encontrado'
			], 404);
		}

		$validator = Validator::make($request->all(), [
			'id_rol' => 'required|exists:rol,id_rol',
			'replace' => 'boolean'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$asignadoPor = $request->user()->id_usuario ?? null;
		$replace = $request->replace ?? false;

		$this->permissionService->copyRoleFunctionsToUser(
			$usuarioId,
			$request->id_rol,
			$replace,
			$asignadoPor
		);

		return response()->json([
			'success' => true,
			'message' => 'Funciones copiadas exitosamente desde el rol'
		]);
	}

	public function checkPermission(Request $request, $usuarioId)
	{
		$validator = Validator::make($request->all(), [
			'codigo' => 'required|string'
		]);

		if ($validator->fails()) {
			return response()->json([
				'success' => false,
				'message' => 'Error de validación',
				'errors' => $validator->errors()
			], 422);
		}

		$hasPermission = $this->permissionService->hasFunction($usuarioId, $request->codigo);

		return response()->json([
			'success' => true,
			'has_permission' => $hasPermission
		]);
	}
}
