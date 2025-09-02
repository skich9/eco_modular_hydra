<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CuotasSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		$now = now();
		$rows = [
			[
				'nombre' => 'Mensualidad 1',
				'descripcion' => 'Primera cuota de mensualidad',
				'monto' => 500,
				'fecha_vencimiento' => now()->startOfMonth()->addMonth()->toDateString(),
				'estado' => true,
				'tipo' => 'MENSUALIDAD',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'nombre' => 'Mensualidad 2',
				'descripcion' => 'Segunda cuota de mensualidad',
				'monto' => 500,
				'fecha_vencimiento' => now()->startOfMonth()->addMonths(2)->toDateString(),
				'estado' => true,
				'tipo' => 'MENSUALIDAD',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'nombre' => 'Matrícula',
				'descripcion' => 'Pago de matrícula',
				'monto' => 300,
				'fecha_vencimiento' => now()->startOfMonth()->toDateString(),
				'estado' => true,
				'tipo' => 'ITEM',
				'created_at' => $now,
				'updated_at' => $now,
			],
		];

		foreach ($rows as $r) {
			DB::table('cuotas')->updateOrInsert(
				['nombre' => $r['nombre']],
				$r
			);
		}
	}
}
