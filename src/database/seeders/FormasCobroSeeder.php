<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FormasCobroSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		$now = now();
		$formas = [
			[
				'id_forma_cobro' => 'EF',
				'nombre' => 'Efectivo',
				'descripcion' => 'Pago en efectivo',
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'id_forma_cobro' => 'TR',
				'nombre' => 'Transferencia',
				'descripcion' => 'Transferencia bancaria',
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'id_forma_cobro' => 'TC',
				'nombre' => 'Tarjeta',
				'descripcion' => 'Tarjeta de débito/crédito',
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'id_forma_cobro' => 'QR',
				'nombre' => 'QR',
				'descripcion' => 'Pago con QR',
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
		];

		foreach ($formas as $f) {
			DB::table('formas_cobro')->updateOrInsert(
				['id_forma_cobro' => $f['id_forma_cobro']],
				$f
			);
		}
	}
}
