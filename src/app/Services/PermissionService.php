<?php

namespace App\Services;

use App\Models\Usuario;
use App\Models\Funcion;
use App\Models\Rol;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PermissionService
{
	public function getUserFunctions(int $userId): array
	{
		$usuario = Usuario::with(['funcionesActivas.roles'])->find($userId);
		
		if (!$usuario) {
			return [];
		}

		$funciones = $usuario->funcionesActivas->map(function ($funcion) {
			return [
				'id_funcion' => $funcion->id_funcion,
				'codigo' => $funcion->codigo,
				'nombre' => $funcion->nombre,
				'descripcion' => $funcion->descripcion,
				'modulo' => $funcion->modulo,
				'icono' => $funcion->icono,
				'fecha_ini' => $funcion->pivot->fecha_ini,
				'fecha_fin' => $funcion->pivot->fecha_fin,
				'observaciones' => $funcion->pivot->observaciones,
			];
		});

		return $funciones->toArray();
	}

	public function hasFunction(int $userId, string $codigoFuncion): bool
	{
		$usuario = Usuario::find($userId);
		
		if (!$usuario) {
			return false;
		}

		return $usuario->funcionesActivas()
			->where('funciones.codigo', $codigoFuncion)
			->exists();
	}

	public function assignFunction(
		int $userId,
		int $funcionId,
		?string $fechaInicio = null,
		?string $fechaFin = null,
		?string $observaciones = null,
		?int $asignadoPor = null
	): bool {
		$fechaInicio = $fechaInicio ?? Carbon::now()->format('Y-m-d');

		$exists = DB::table('asignacion_funcion')
			->where('id_usuario', $userId)
			->where('id_funcion', $funcionId)
			->exists();

		if ($exists) {
			DB::table('asignacion_funcion')
				->where('id_usuario', $userId)
				->where('id_funcion', $funcionId)
				->update([
					'fecha_ini' => $fechaInicio,
					'fecha_fin' => $fechaFin,
					'activo' => true,
					'observaciones' => $observaciones,
					'asignado_por' => $asignadoPor,
					'updated_at' => Carbon::now(),
				]);
		} else {
			DB::table('asignacion_funcion')->insert([
				'id_usuario' => $userId,
				'id_funcion' => $funcionId,
				'fecha_ini' => $fechaInicio,
				'fecha_fin' => $fechaFin,
				'activo' => true,
				'observaciones' => $observaciones,
				'asignado_por' => $asignadoPor,
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			]);
		}

		return true;
	}

	public function removeFunction(int $userId, int $funcionId): bool
	{
		DB::table('asignacion_funcion')
			->where('id_usuario', $userId)
			->where('id_funcion', $funcionId)
			->update([
				'activo' => false,
				'updated_at' => Carbon::now(),
			]);

		return true;
	}

	/**
	 * Sincroniza las funciones de un usuario.
	 * Desactiva las funciones que no están en la lista y activa/crea las que sí están.
	 */
	public function syncFunctions(int $userId, array $functions, ?int $asignadoPor = null): bool
	{
		return DB::transaction(function () use ($userId, $functions, $asignadoPor) {
			// 1. Desactivar todas las funciones actuales y marcarlas como sincronización manual
			DB::table('asignacion_funcion')
				->where('id_usuario', $userId)
				->update([
					'activo' => false,
					'observaciones' => 'Sincronizado desde gestión de usuario',
					'updated_at' => Carbon::now(),
				]);

			// 2. Activar/Insertar las nuevas
			foreach ($functions as $funcionId) {
				$this->assignFunction(
					$userId,
					$funcionId,
					null, // fecha_ini por defecto hoy
					null, // fecha_fin null
					'Sincronizado desde gestión de usuario',
					$asignadoPor
				);
			}

			return true;
		});
	}


	public function copyRoleFunctionsToUser(int $userId, int $rolId, bool $replace = false, ?int $asignadoPor = null): bool
	{
		$rol = Rol::with('funciones')->find($rolId);
		
		if (!$rol) {
			throw new \Exception("Rol no encontrado");
		}

		if ($replace) {
			// Solo desactivar funciones que fueron copiadas del rol, NO los ajustes manuales
			DB::table('asignacion_funcion')
				->where('id_usuario', $userId)
				->where('observaciones', 'like', 'Copiado desde rol%')
				->update(['activo' => false, 'updated_at' => Carbon::now()]);
		}

		$fechaInicio = Carbon::now()->format('Y-m-d');

		foreach ($rol->funciones as $funcion) {
			// VERIFICACIÓN DE PROTECCIÓN: Si existe un ajuste manual (activo o inactivo), no sobrescribir
			$tieneAjusteManual = DB::table('asignacion_funcion')
				->where('id_usuario', $userId)
				->where('id_funcion', $funcion->id_funcion)
				->where('observaciones', 'not like', 'Copiado desde rol%')
				->whereNotNull('observaciones')
				->exists();

			if ($tieneAjusteManual) {
				continue; // Respetar el ajuste manual del usuario
			}

			$this->assignFunction(
				$userId,
				$funcion->id_funcion,
				$fechaInicio,
				null,
				"Copiado desde rol: {$rol->nombre}",
				$asignadoPor
			);
		}

		return true;
	}

	public function cleanExpiredFunctions(): int
	{
		$count = DB::table('asignacion_funcion')
			->where('activo', true)
			->whereNotNull('fecha_fin')
			->where('fecha_fin', '<', Carbon::now()->format('Y-m-d'))
			->update([
				'activo' => false,
				'updated_at' => Carbon::now(),
			]);

		return $count;
	}

	public function getUserFunctionsByModule(int $userId): array
	{
		$funciones = $this->getUserFunctions($userId);
		
		$grouped = [];
		foreach ($funciones as $funcion) {
			$modulo = $funcion['modulo'] ?? 'sin_modulo';
			if (!isset($grouped[$modulo])) {
				$grouped[$modulo] = [];
			}
			$grouped[$modulo][] = $funcion;
		}

		return $grouped;
	}

	/**
	 * Sincroniza las funciones de un rol para TODOS los usuarios que tienen ese rol asignado.
	 */
	public function syncRoleFunctionsToAllUsers(int $rolId, ?int $asignadoPor = null): bool
	{
		$usuarios = Usuario::where('id_rol', $rolId)
			->where('estado', true)
			->get();

		foreach ($usuarios as $usuario) {
			$this->copyRoleFunctionsToUser(
				$usuario->id_usuario,
				$rolId,
				true, // Reemplazar funciones existentes
				$asignadoPor
			);
		}

		return true;
	}
}
