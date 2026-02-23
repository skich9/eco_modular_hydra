<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinActividadesSeeder extends Seeder
{
	public function run()
	{
		$actividades = [
			[
				'codigo_caeb' => '853000',
				'descripcion' => 'ENSEÑANZA SUPERIOR',
				'tipo_actividad' => 'P',
			],
			[
				'codigo_caeb' => '8530400',
				'descripcion' => 'Docencia en Educación Técnica y No Universitaria',
				'tipo_actividad' => 'P',
			],
			[
				'codigo_caeb' => '854000',
				'descripcion' => 'ENSEÑANZA DE ADULTOS Y OTROS TIPOS DE ENSEÑANZA',
				'tipo_actividad' => 'S',
			],
		];

		foreach ($actividades as $actividad) {
			DB::table('sin_actividades')->updateOrInsert(
				['codigo_caeb' => $actividad['codigo_caeb']],
				$actividad
			);
		}
	}
}
