<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GestionSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		// Inserta las gestiones necesarias para los seeders dependientes
		$now = now();

		$rows = [
			[
				'gestion' => '1/1999',
				'fecha_ini' => '1999-01-01',
				'fecha_fin' => '1999-12-31',
				'orden' => 1,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2/1999',
				'fecha_ini' => '1999-01-01',
				'fecha_fin' => '1999-12-31',
				'orden' => 2,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '1/2000',
				'fecha_ini' => '2000-01-01',
				'fecha_fin' => '2000-12-31',
				'orden' => 3,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2/2000',
				'fecha_ini' => '2000-01-01',
				'fecha_fin' => '2000-12-31',
				'orden' => 4,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
		];

		foreach ($rows as $row) {
			// Upsert simple para evitar duplicados si ya existe
			$exists = DB::table('gestion')->where('gestion', $row['gestion'])->exists();
			if (!$exists) {
				DB::table('gestion')->insert($row);
			}
		}
	}
}

