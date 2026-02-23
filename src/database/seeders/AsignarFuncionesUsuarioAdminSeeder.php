<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsignarFuncionesUsuarioAdminSeeder extends Seeder
{
	/**
	 * Run the database seeder.
	 * Asigna todas las funciones de menú directamente al usuario Admin en la tabla asignacion_funcion
	 */
	public function run()
	{
		$now = Carbon::now();
		$fechaInicio = $now->format('Y-m-d');

		// Buscar el usuario Admin (puede ser por nickname o por rol)
		$usuarioAdmin = DB::table('usuarios')
			->where('nickname', 'LIKE', '%admin%')
			->orWhere('nickname', 'LIKE', '%Admin%')
			->first();

		if (!$usuarioAdmin) {
			// Si no se encuentra por nickname, buscar el primer usuario con rol Administrador
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

		// Obtener todas las funciones de menú
		$funciones = DB::table('funciones')
			->where('activo', true)
			->whereIn('codigo', [
				// Cobros
				'cobros_gestionar',

				// Reportes
				'reportes_libro_diario',

				// Reimpresión
				'reimpresion_facturacion_posterior',

				// Académico
				'academico_asignacion_becas',

				// SIN
				'sin_estado_factura',
				'sin_contingencias',
				'sin_configuracion_punto_venta',

				// Económico
				'economico_prorroga_mora',
				'economico_descuento_mora',

				// Configuración
				'configuracion_usuarios',
				'configuracion_roles',
				'configuracion_parametros',
				'configuracion_descuentos',
				'configuracion_costos',
				'configuracion_costos_creditos',
				'configuracion_mora',
				'configuracion_generales'
			])
			->get();

		if ($funciones->isEmpty()) {
			$this->command->error('No se encontraron funciones de menú. Ejecuta primero: php artisan db:seed --class=FuncionesMenuSeeder');
			return;
		}

		$this->command->info("Funciones encontradas: {$funciones->count()}");

		// Asignar todas las funciones al usuario Admin en asignacion_funcion
		$asignadas = 0;
		foreach ($funciones as $funcion) {
			// Verificar si ya existe la asignación
			$existe = DB::table('asignacion_funcion')
				->where('id_usuario', $usuarioAdmin->id_usuario)
				->where('id_funcion', $funcion->id_funcion)
				->exists();

			if (!$existe) {
				DB::table('asignacion_funcion')->insert([
					'id_usuario' => $usuarioAdmin->id_usuario,
					'id_funcion' => $funcion->id_funcion,
					'fecha_ini' => $fechaInicio,
					'fecha_fin' => null,
					'activo' => true,
					'observaciones' => 'Asignado por seeder inicial - Usuario Administrador',
					'asignado_por' => null,
					'created_at' => $now,
					'updated_at' => $now
				]);
				$asignadas++;
				$this->command->info("  ✓ Asignada: {$funcion->nombre} ({$funcion->codigo})");
			} else {
				// Actualizar para asegurar que esté activa
				DB::table('asignacion_funcion')
					->where('id_usuario', $usuarioAdmin->id_usuario)
					->where('id_funcion', $funcion->id_funcion)
					->update([
						'activo' => true,
						'fecha_ini' => $fechaInicio,
						'fecha_fin' => null,
						'updated_at' => $now
					]);
				$this->command->comment("  - Actualizada: {$funcion->nombre} ({$funcion->codigo})");
			}
		}

		$this->command->info("\n✅ Proceso completado:");
		$this->command->info("   - Funciones nuevas asignadas: {$asignadas}");
		$this->command->info("   - Total de funciones activas del usuario: " . DB::table('asignacion_funcion')
			->where('id_usuario', $usuarioAdmin->id_usuario)
			->where('activo', true)
			->count());
	}
}
