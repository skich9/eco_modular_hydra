<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AsignarFuncionesAdminSeeder extends Seeder
{
	/**
	 * Run the database seeder.
	 * Asigna todas las funciones de menú al rol Administrador
	 */
	public function run()
	{
		$now = Carbon::now();

		// Buscar el rol Administrador (puede ser por nombre o por ID conocido)
		$rolAdmin = DB::table('rol')
			->where('nombre', 'LIKE', '%Administrador%')
			->orWhere('nombre', 'LIKE', '%Admin%')
			->first();

		if (!$rolAdmin) {
			$this->command->error('No se encontró el rol Administrador');
			return;
		}

		$this->command->info("Rol encontrado: {$rolAdmin->nombre} (ID: {$rolAdmin->id_rol})");

		// Obtener todas las funciones de menú (las que tienen códigos que empiezan con 'menu_' o pertenecen a los módulos del sistema)
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

		// Asignar todas las funciones al rol Administrador
		$asignadas = 0;
		foreach ($funciones as $funcion) {
			// Verificar si ya existe la asignación
			$existe = DB::table('rol_funcion')
				->where('id_rol', $rolAdmin->id_rol)
				->where('id_funcion', $funcion->id_funcion)
				->exists();

			if (!$existe) {
				DB::table('rol_funcion')->insert([
					'id_rol' => $rolAdmin->id_rol,
					'id_funcion' => $funcion->id_funcion,
					'created_at' => $now,
					'updated_at' => $now
				]);
				$asignadas++;
				$this->command->info("  ✓ Asignada: {$funcion->nombre} ({$funcion->codigo})");
			} else {
				$this->command->comment("  - Ya existe: {$funcion->nombre} ({$funcion->codigo})");
			}
		}

		$this->command->info("\n✅ Proceso completado:");
		$this->command->info("   - Funciones asignadas: {$asignadas}");
		$this->command->info("   - Total de funciones del rol: " . DB::table('rol_funcion')->where('id_rol', $rolAdmin->id_rol)->count());
	}
}
