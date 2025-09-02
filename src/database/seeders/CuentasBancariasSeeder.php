<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CuentasBancariasSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		$now = now();
		$rows = [
			[
				'banco' => 'Banco Unión',
				'numero_cuenta' => '1234567890',
				'tipo_cuenta' => 'CAJA DE AHORRO',
				'titular' => 'Instituto X',
				'habilitado_QR' => true,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'banco' => 'Banco BISA',
				'numero_cuenta' => '9876543210',
				'tipo_cuenta' => 'CUENTA CORRIENTE',
				'titular' => 'Instituto X',
				'habilitado_QR' => false,
				'estado' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
		];

		foreach ($rows as $r) {
			DB::table('cuentas_bancarias')->updateOrInsert(
				['numero_cuenta' => $r['numero_cuenta']],
				$r
			);
		}
	}
}
