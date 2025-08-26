<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}

		// Asegurar tipos correctos para poder crear FKs (evitamos doctrine/dbal usando SQL crudo)
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_descuentoDetalle BIGINT UNSIGNED NULL');
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_prorroga BIGINT UNSIGNED NULL');
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_compromisos BIGINT UNSIGNED NULL');

		Schema::table('asignacion_costos', function (Blueprint $table) {
			// Relaciones faltantes
			$table->foreign('id_descuentoDetalle')
				->references('id_descuento_detalle')
				->on('descuento_detalle')
				->onDelete('restrict')
				->onUpdate('restrict');

			$table->foreign('id_prorroga')
				->references('id_prorroga')
				->on('prorrogas')
				->onDelete('restrict')
				->onUpdate('restrict');

			$table->foreign('id_compromisos')
				->references('id_compromisos')
				->on('compromisos')
				->onDelete('restrict')
				->onUpdate('restrict');
		});
	}

	public function down(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}

		Schema::table('asignacion_costos', function (Blueprint $table) {
			// Eliminar FKs añadidas
			$table->dropForeign(['id_descuentoDetalle']);
			$table->dropForeign(['id_prorroga']);
			$table->dropForeign(['id_compromisos']);
		});

		// Revertir tipos a los originales
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_descuentoDetalle VARCHAR(255) NULL');
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_prorroga INT NULL');
		DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_compromisos INT NULL');
	}
};
