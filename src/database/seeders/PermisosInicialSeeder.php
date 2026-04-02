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

		// 2. Asignar funciones al rol Administrador (rol_funcion)
		$this->command->info('2️⃣  Asignando funciones al rol Administrador...');
		$this->call(AsignarFuncionesAdminSeeder::class);
		$this->command->newLine();

		// 3. Replicar en asignacion_funcion lo definido en rol_funcion para el usuario admin
		$this->command->info('3️⃣  Sincronizando asignacion_funcion del usuario Administrador...');
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
