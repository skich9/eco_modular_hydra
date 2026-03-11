<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


class AlterQrConceptosDetalleAddCobroFields extends Migration
{
	public function up()
	{
		if (!Schema::hasTable('qr_conceptos_detalle')) {
			return;
		}

		Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
			if (!Schema::hasColumn('qr_conceptos_detalle', 'cod_tipo_cobro')) {
				$table->string('cod_tipo_cobro', 50)->nullable()->after('medio_doc');
			}
			if (!Schema::hasColumn('qr_conceptos_detalle', 'tipo_pago')) {
				$table->string('tipo_pago', 50)->nullable()->after('cod_tipo_cobro');
			}
			if (!Schema::hasColumn('qr_conceptos_detalle', 'id_asignacion_mora')) {
				$table->unsignedBigInteger('id_asignacion_mora')->nullable()->after('tipo_pago');
			}
			if (!Schema::hasColumn('qr_conceptos_detalle', 'id_asignacion_costo')) {
				$table->unsignedBigInteger('id_asignacion_costo')->nullable()->after('id_asignacion_mora');
			}
			if (!Schema::hasColumn('qr_conceptos_detalle', 'id_cuota')) {
				$table->unsignedBigInteger('id_cuota')->nullable()->after('id_asignacion_costo');
			}
		});
	}

	public function down()
	{
		if (!Schema::hasTable('qr_conceptos_detalle')) {
			return;
		}

		Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
			$cols = [
				'cod_tipo_cobro',
				'tipo_pago',
				'id_asignacion_mora',
				'id_asignacion_costo',
				'id_cuota',
			];
			foreach ($cols as $col) {
				if (Schema::hasColumn('qr_conceptos_detalle', $col)) {
					$table->dropColumn($col);
				}
			}
		});
	}

}
