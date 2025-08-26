<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos') || !Schema::hasTable('costo_semestral')) {
			return;
		}

		Schema::table('asignacion_costos', function (Blueprint $table) {
			// Agrega FK simple a costo_semestral.id_costo_semestral como en el SQL de referencia
			$table->foreign('id_costo_semestral')
				->references('id_costo_semestral')
				->on('costo_semestral')
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
			// Elimina la FK añadida
			$table->dropForeign(['id_costo_semestral']);
		});
	}
};
