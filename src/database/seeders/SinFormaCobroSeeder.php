<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinFormaCobroSeeder extends Seeder
{
	public function run()
	{
		$rows = [
			['codigo_sin' => 1, 'descripcion_sin' => 'EFECTIVO', 'id_forma_cobro' => 'E', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 2, 'descripcion_sin' => 'TARJETA', 'id_forma_cobro' => 'L', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 3, 'descripcion_sin' => 'CHEQUE', 'id_forma_cobro' => 'C', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 4, 'descripcion_sin' => 'VALES', 'id_forma_cobro' => 'O', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 5, 'descripcion_sin' => 'OTROS', 'id_forma_cobro' => 'O', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 6, 'descripcion_sin' => 'PAGO POSTERIOR', 'id_forma_cobro' => 'O', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 7, 'descripcion_sin' => 'TRANSFERENCIA BANCARIA', 'id_forma_cobro' => 'B', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			['codigo_sin' => 8, 'descripcion_sin' => 'DEPOSITO EN CUENTA', 'id_forma_cobro' => 'D', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
			// Mantener 'VALES – CANAL DE PAGO' activo por integración QR/canales
			['codigo_sin' => 54, 'descripcion_sin' => 'VALES – CANAL DE PAGO', 'id_forma_cobro' => 'O', 'activo' => 1, 'created_at' => '2025-09-11 19:14:18', 'updated_at' => '2025-10-08 22:18:09'],
		];

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('sin_forma_cobro')->truncate();
		DB::table('sin_forma_cobro')->insert($rows);
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
