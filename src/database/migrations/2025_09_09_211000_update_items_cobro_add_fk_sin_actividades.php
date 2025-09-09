<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('items_cobro', function (Blueprint $table) {
			// Alinear tipo/longitud para permitir la FK como en el SQL fuente
			$table->string('actividad_economica', 25)->change();
		});

		Schema::table('items_cobro', function (Blueprint $table) {
			$table->foreign('actividad_economica')
				->references('codigo_caeb')
				->on('sin_actividades')
				->restrictOnDelete()
				->restrictOnUpdate();
		});
	}

	public function down(): void
	{
		Schema::table('items_cobro', function (Blueprint $table) {
			// Eliminar FK si existe
			try { $table->dropForeign(['actividad_economica']); } catch (\Throwable $e) {}
			// Revertir longitud al valor previo (255)
			$table->string('actividad_economica', 255)->change();
		});
	}
};
