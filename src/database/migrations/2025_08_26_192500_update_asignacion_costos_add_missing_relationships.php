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
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_descuentoDetalle BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_prorroga BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_compromisos BIGINT UNSIGNED NULL'); } catch (\Throwable $e) {}

		// Agregar FKs solo si no existen
		$fkMap = [
			['column' => 'id_descuentoDetalle', 'ref_table' => 'descuento_detalle', 'ref_column' => 'id_descuento_detalle'],
			['column' => 'id_prorroga', 'ref_table' => 'prorrogas', 'ref_column' => 'id_prorroga'],
			['column' => 'id_compromisos', 'ref_table' => 'compromisos', 'ref_column' => 'id_compromisos'],
		];

		foreach ($fkMap as $fk) {
			$exists = DB::selectOne("SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignacion_costos' AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1", [$fk['column']]);
			if (!$exists) {
				Schema::table('asignacion_costos', function (Blueprint $table) use ($fk) {
					$table->foreign($fk['column'])
						->references($fk['ref_column'])
						->on($fk['ref_table'])
						->onDelete('restrict')
						->onUpdate('restrict');
				});
			}
		}
	}

	public function down(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}

		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropForeign(['id_descuentoDetalle']); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropForeign(['id_prorroga']); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropForeign(['id_compromisos']); }); } catch (\Throwable $e) {}

		// Revertir tipos a los originales (si aplica)
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_descuentoDetalle VARCHAR(255) NULL'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_prorroga INT NULL'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE asignacion_costos MODIFY COLUMN id_compromisos INT NULL'); } catch (\Throwable $e) {}
	}
};

