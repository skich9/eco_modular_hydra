<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OtrosIngresosCatalogosSeeder extends Seeder
{
	public function run(): void
	{
		$now = now();

		if (Schema::hasTable('tipo_otro_ingreso')) {
			$tipos = [
				['cod_tipo_ingreso' => 'ANU', 'nom_tipo_ingreso' => 'Anulado', 'descripcion_tipo_ingreso' => 'Registro de anulación (equivalente SGA)'],
				['cod_tipo_ingreso' => 'FOT', 'nom_tipo_ingreso' => 'Fotocopiadora', 'descripcion_tipo_ingreso' => null],
				['cod_tipo_ingreso' => 'ALQ', 'nom_tipo_ingreso' => 'Alquileres', 'descripcion_tipo_ingreso' => null],
				['cod_tipo_ingreso' => 'TDA', 'nom_tipo_ingreso' => 'Tienda', 'descripcion_tipo_ingreso' => null],
				['cod_tipo_ingreso' => 'OT', 'nom_tipo_ingreso' => 'Ingreso por Ordenes de Trabajo', 'descripcion_tipo_ingreso' => null],
				['cod_tipo_ingreso' => 'VAR', 'nom_tipo_ingreso' => 'Varios', 'descripcion_tipo_ingreso' => null],
			];
			foreach ($tipos as $t) {
				DB::table('tipo_otro_ingreso')->updateOrInsert(
					['cod_tipo_ingreso' => $t['cod_tipo_ingreso']],
					array_merge($t, ['created_at' => $now, 'updated_at' => $now])
				);
			}
		}

		if (Schema::hasTable('eco_directiva_gestion')) {
			$gestion = Schema::hasTable('gestion')
				? (DB::table('gestion')->orderByDesc('fecha_ini')->value('gestion') ?? 'DEMO-GESTION')
				: 'DEMO-GESTION';
			$codPensum = Schema::hasTable('pensums')
				? (string) (DB::table('pensums')->orderBy('cod_pensum')->value('cod_pensum') ?? '')
				: '';
			$numeroAut = 'DEMO-AUT-1';
			DB::table('eco_directiva_gestion')->updateOrInsert(
				['gestion' => $gestion, 'cod_pensum' => $codPensum, 'numero_aut' => $numeroAut],
				[
					'tipo_facturacion' => 'MANUAL',
					'descripcion' => null,
					'num_fact_ini' => 1,
					'num_fact_fin' => 9_999_999,
					'activo' => true,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
		}
	}
}
