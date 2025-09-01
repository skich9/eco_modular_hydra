<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos') || !Schema::hasTable('costo_semestral')) {
			return;
		}

		// Solo agregar la FK si no existe
		$exists = DB::selectOne("SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignacion_costos' AND COLUMN_NAME = 'id_costo_semestral' AND REFERENCED_TABLE_NAME = 'costo_semestral' LIMIT 1");
		if (!$exists) {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				// Agrega FK simple a costo_semestral.id_costo_semestral como en el SQL de referencia
				$table->foreign('id_costo_semestral')
					->references('id_costo_semestral')
					->on('costo_semestral')
					->onDelete('restrict')
					->onUpdate('restrict');
			});
		}
	}

	public function down(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}

		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				// Elimina la FK añadida
				$table->dropForeign(['id_costo_semestral']);
			});
		} catch (\Throwable $e) {
			// ignorar si no existe
		}
	}
};
