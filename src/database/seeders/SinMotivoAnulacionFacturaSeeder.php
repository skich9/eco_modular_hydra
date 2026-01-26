<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinMotivoAnulacionFacturaSeeder extends Seeder
{
	public function run()
	{
		$rows = [
			['codigo_id' => 1, 'descripcion' => 'FACTURA MAL EMITIDA'],
			['codigo_id' => 2, 'descripcion' => 'NOTA DE CREDITO-DEBITO MAL EMITIDA'],
			['codigo_id' => 3, 'descripcion' => 'DATOS DE EMISION INCORRECTOS'],
			['codigo_id' => 4, 'descripcion' => 'FACTURA O NOTA DE CREDITO-DEBITO DEVUELTA'],
		];

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('sin_motivo_anulacion_factura')->truncate();
		DB::table('sin_motivo_anulacion_factura')->insert($rows);
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
