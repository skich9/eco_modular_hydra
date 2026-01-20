<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParametrosGeneralesSeeder extends Seeder
{
	public function run()
	{
		DB::table('parametros_generales')->insert([
			[
				'nombre' => 'Años de la institucion',
				'valor' => '50',
				'estado' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre' => 'Nombre de la Institucion',
				'valor' => 'Instituto Tecnológico CETA',
				'estado' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
		]);
	}
}
