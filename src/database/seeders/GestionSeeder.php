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
				'gestion' => '2024-1',
				'fecha_ini' => '2024-01-01',
				'fecha_fin' => '2024-06-30',
				'orden' => 1,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2024-2',
				'fecha_ini' => '2024-07-01',
				'fecha_fin' => '2024-12-31',
				'orden' => 2,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2025-1',
				'fecha_ini' => '2025-01-01',
				'fecha_fin' => '2025-06-30',
				'orden' => 3,
				'fecha_graduacion' => null,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2025-2',
				'fecha_ini' => '2025-07-01',
				'fecha_fin' => '2025-12-31',
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

