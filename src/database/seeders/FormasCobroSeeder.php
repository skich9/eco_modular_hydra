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
		$formas = [
			[
				'id_forma_cobro' => 'B',
				'nombre' => 'Transferencia',
				'descripcion' => 'Se utiliza para realizar cobros con transferencia bancaria',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'C',
				'nombre' => 'Cheque',
				'descripcion' => 'Se utiliza para registrar cobros realizados con cheque',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'D',
				'nombre' => 'Deposito',
				'descripcion' => 'Se utiliza para registrar cobros realizados con deposito bancario',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'E',
				'nombre' => 'Efectivo',
				'descripcion' => 'Se utiliza para registrar cobros realizados con dinero en efectivo',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'L',
				'nombre' => 'Tarjeta',
				'descripcion' => 'Se utiliza para realizar cobros con transferencia o deposito en linea (QR)',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'O',
				'nombre' => 'Otros',
				'descripcion' => 'Se utiliza para registrar cobros realizados con otros medios de pago',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_forma_cobro' => 'T',
				'nombre' => 'Traspaso',
				'descripcion' => 'Se utiliza para registrar cobros realizados con tarjeta de debito o credito',
				'estado' => '1',
				'created_at' => null,
				'updated_at' => null,
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
