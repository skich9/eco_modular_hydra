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
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2/1999',
				'fecha_ini' => '1999-01-01',
				'fecha_fin' => '1999-12-31',
				'orden' => 2,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '1/2000',
				'fecha_ini' => '2000-01-01',
				'fecha_fin' => '2000-12-31',
				'orden' => 3,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'gestion' => '2/2000',
				'fecha_ini' => '2000-01-01',
				'fecha_fin' => '2000-12-31',
				'orden' => 4,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '1/2023',
				'fecha_ini' => '2023-02-15',
				'fecha_fin' => '2023-06-22',
				'orden' => 17,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '2/2023',
				'fecha_ini' => '2023-07-16',
				'fecha_fin' => '2023-11-30',
				'orden' => 18,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '1/2024',
				'fecha_ini' => '2024-02-15',
				'fecha_fin' => '2024-06-22',
				'orden' => 19,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '2/2024',
				'fecha_ini' => '2024-07-16',
				'fecha_fin' => '2024-11-30',
				'orden' => 20,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '1/2025',
				'fecha_ini' => '2025-02-15',
				'fecha_fin' => '2025-06-22',
				'orden' => 21,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '2/2025',
				'fecha_ini' => '2025-07-16',
				'fecha_fin' => '2025-11-30',
				'orden' => 22,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '1/2026',
				'fecha_ini' => '2026-02-15',
				'fecha_fin' => '2026-06-22',
				'orden' => 23,
				'fecha_graduacion' => null,
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
            [
				'gestion' => '2/2026',
				'fecha_ini' => '2026-07-16',
				'fecha_fin' => '2026-11-30',
				'orden' => 24,
				'fecha_graduacion' => null,
				'activo' => true,
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

