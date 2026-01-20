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
                'I_R' => false,
                'doc_tipo_preferido' => null,
                'qr_url_auth' => 'https://dev-sip.mc4.com.bo:8443/autenticacion/v1/generarToken',
                'qr_api_key' => 'null',
                'qr_username' => 'null',
                'qr_password' => 'null',
                'qr_usl_transfer' => 'https://dev-sip.mc4.com.bo:8443',
                'qr_api_key_servicio' => 'null',
                'qr_http_verify_ssl' => '0',
                'qr_http_timeout' => '60',
                'qr_http_connect_timeout' => '20',
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
                'I_R' => true,
                'doc_tipo_preferido' => null,
                'qr_url_auth' => 'https://dev-sip.mc4.com.bo:8443/autenticacion/v1/generarToken',
                'qr_api_key' => 'null',
                'qr_username' => 'null',
                'qr_password' => 'null',
                'qr_usl_transfer' => 'https://dev-sip.mc4.com.bo:8443',
                'qr_api_key_servicio' => 'null',
                'qr_http_verify_ssl' => '0',
                'qr_http_timeout' => '60',
                'qr_http_connect_timeout' => '20',
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
