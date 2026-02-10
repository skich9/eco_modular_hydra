<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PermisosInicialSeeder extends Seeder
{
	/**
	 * Run the database seeder.
	 * Ejecuta todos los seeders necesarios para configurar el sistema de permisos
	 */
	public function run()
	{
		$this->command->info('========================================');
		$this->command->info('  CONFIGURACIÓN INICIAL DE PERMISOS');
		$this->command->info('========================================');
		$this->command->newLine();

		// 1. Insertar funciones de menú
		$this->command->info('1️⃣  Insertando funciones de menú...');
		$this->call(FuncionesMenuSeeder::class);
		$this->command->newLine();

		// 2. Asignar funciones al usuario Administrador en asignacion_funcion
		$this->command->info('2️⃣  Asignando funciones al usuario Administrador...');
		$this->call(AsignarFuncionesUsuarioAdminSeeder::class);
		$this->command->newLine();

		$this->command->info('========================================');
		$this->command->info('  ✅ CONFIGURACIÓN COMPLETADA');
		$this->command->info('========================================');
		$this->command->newLine();
		$this->command->info('Ahora puedes:');
		$this->command->info('  1. Cerrar sesión y volver a iniciar sesión');
		$this->command->info('  2. Verificar que el menú de navegación muestra todas las opciones');
		$this->command->info('  3. Asignar funciones a otros roles desde Configuración > Roles');
		$this->command->newLine();
	}
}
