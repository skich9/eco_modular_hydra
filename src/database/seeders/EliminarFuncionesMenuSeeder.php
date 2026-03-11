<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EliminarFuncionesMenuSeeder extends Seeder
{
	/**
	 * Eliminar las funciones de menú (menu_*) de la base de datos.
	 * Ahora usaremos el campo 'modulo' para determinar la visibilidad de los botones de navegación.
	 */
	public function run()
	{
		$this->command->info('Eliminando funciones de menú...');

		// Eliminar todas las funciones que empiezan con 'menu_'
		$deletedCount = DB::table('funciones')
			->where('codigo', 'like', 'menu_%')
			->delete();

		$this->command->info("Se eliminaron {$deletedCount} funciones de menú.");
		$this->command->info('Ahora la visibilidad de los botones de navegación se basará en el campo modulo de las funciones asignadas.');
	}
}
