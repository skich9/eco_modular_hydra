<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinActividadesSeeder extends Seeder
{
	public function run()
	{
		DB::table('sin_actividades')->insert([
			[
				'codigo_caeb' => '853000',
				'descripcion' => 'ENSEÑANZA SUPERIOR',
				'tipo_actividad' => 'P',
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'codigo_caeb' => '8530400',
				'descripcion' => 'Docencia en Educación Técnica y No Universitaria',
				'tipo_actividad' => 'P',
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'codigo_caeb' => '854000',
				'descripcion' => 'ENSEÑANZA DE ADULTOS Y OTROS TIPOS DE ENSEÑANZA',
				'tipo_actividad' => 'S',
				'created_at' => now(),
				'updated_at' => now(),
			],
		]);
	}
}
