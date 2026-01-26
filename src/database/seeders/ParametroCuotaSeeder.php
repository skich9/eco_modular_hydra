<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ParametroCuotaSeeder extends Seeder
{
	public function run()
	{
		DB::table('parametros_cuota')->insert([
			[
				'nombre_cuota' => 'Cuota 1',
				'fecha_vencimiento' => '2025-07-16',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_cuota' => 'Cuota 2',
				'fecha_vencimiento' => '2025-08-15',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_cuota' => 'Cuota 3',
				'fecha_vencimiento' => '2025-09-15',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_cuota' => 'Cuota 4',
				'fecha_vencimiento' => '2025-10-15',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
			[
				'nombre_cuota' => 'Cuota 5',
				'fecha_vencimiento' => '2025-11-17',
				'activo' => 1,
				'created_at' => now(),
				'updated_at' => now(),
			],
		]);
	}
}
