<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParametrosCostosSeeder extends Seeder
{
	public function run()
	{
		DB::table('parametros_costos')->insert([
			[
				'nombre_costo' => 'costo_mensual',
				'nombre_oficial' => 'Costo Mensual',
				'descripcion' => 'MENSUALIDAD',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'materia',
				'nombre_oficial' => 'Materia Arrastre',
				'descripcion' => 'MATERIA (S) ARRASTRE',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'rezagado',
				'nombre_oficial' => 'Rezagado',
				'descripcion' => 'REZAGADO EXAMEN',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'reincorporacion',
				'nombre_oficial' => 'Reincorporación',
				'descripcion' => 'era por mas de un mes',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'instancia',
				'nombre_oficial' => 'Prueba de Recuperación',
				'descripcion' => 'PRUEBA DE RECUPERACION',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'prueba',
				'nombre_oficial' => 'Pruebita',
				'descripcion' => 'probar la funcion',
				'activo' => 0,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_costo' => 'Costo Curso',
				'nombre_oficial' => 'Costo Curso Oficial',
				'descripcion' => 'Se define el costo del curso',
				'activo' => 0,
				'created_at' => now(),
				'updated_at' => now(),
			],
		]);
	}
}
