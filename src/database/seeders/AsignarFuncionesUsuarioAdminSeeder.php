<?php

namespace Database\Seeders;

use App\Services\PermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AsignarFuncionesUsuarioAdminSeeder extends Seeder
{
	/**
	 * Replica en `asignacion_funcion` las funciones del rol del usuario administrador
	 * (según `rol_funcion`), para que el login API cargue los mismos permisos.
	 */
	public function run(): void
	{
		$usuarioAdmin = DB::table('usuarios')
			->where('nickname', 'LIKE', '%admin%')
			->orWhere('nickname', 'LIKE', '%Admin%')
			->first();

		if (!$usuarioAdmin) {
			$rolAdmin = DB::table('rol')
				->where('nombre', 'LIKE', '%Administrador%')
				->orWhere('nombre', 'LIKE', '%Admin%')
				->first();

			if ($rolAdmin) {
				$usuarioAdmin = DB::table('usuarios')
					->where('id_rol', $rolAdmin->id_rol)
					->first();
			}
		}

		if (!$usuarioAdmin) {
			$this->command->error('No se encontró el usuario Administrador');
			return;
		}

		$this->command->info("Usuario encontrado: {$usuarioAdmin->nickname} (ID: {$usuarioAdmin->id_usuario})");

		$rolId = (int) $usuarioAdmin->id_rol;
		$enRol = DB::table('rol_funcion')->where('id_rol', $rolId)->count();
		if ($enRol === 0) {
			$this->command->warn("El rol {$rolId} no tiene funciones en rol_funcion. Ejecuta FuncionesMenuSeeder y AsignarFuncionesAdminSeeder antes.");
		}

		/** @var PermissionService $permisos */
		$permisos = app(PermissionService::class);
		$permisos->copyRoleFunctionsToUser((int) $usuarioAdmin->id_usuario, $rolId, false, null);

		$total = DB::table('asignacion_funcion')
			->where('id_usuario', $usuarioAdmin->id_usuario)
			->where('activo', true)
			->count();

		$this->command->info('✅ asignacion_funcion sincronizada con rol_funcion del administrador.');
		$this->command->info("   - Funciones activas en asignacion_funcion: {$total}");
	}
}
