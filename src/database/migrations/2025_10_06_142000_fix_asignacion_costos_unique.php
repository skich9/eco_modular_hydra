<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos')) return;

		Schema::table('asignacion_costos', function (Blueprint $table) {
			// Asegurar columna numero_cuota existe (por seguridad)
			if (!Schema::hasColumn('asignacion_costos', 'numero_cuota')) {
				$table->smallInteger('numero_cuota')->nullable()->after('id_compromisos');
			}
		});

		// Intentar eliminar cualquier UNIQUE anterior de 3 columnas
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropUnique('asignacion_costos_unique'); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropUnique('asignacion_costos_cod_pensum_cod_inscrip_id_costo_semestral_unique'); }); } catch (\Throwable $e) {}
		// Drop por columnas (Laravel generará el nombre)
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropUnique(['cod_pensum','cod_inscrip','id_costo_semestral']); }); } catch (\Throwable $e) {}

		// Crear UNIQUE nuevo incluyendo numero_cuota (si no existe ya)
		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				$table->unique(['cod_pensum','cod_inscrip','id_costo_semestral','numero_cuota'], 'asignacion_costos_unique_cuota');
			});
		} catch (\Throwable $e) {}
	}

	public function down(): void
	{
		if (!Schema::hasTable('asignacion_costos')) return;

		// Quitar UNIQUE con numero_cuota
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropUnique('asignacion_costos_unique_cuota'); }); } catch (\Throwable $e) {}

		// Restaurar UNIQUE anterior de 3 columnas
		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				$table->unique(['cod_pensum','cod_inscrip','id_costo_semestral'], 'asignacion_costos_unique');
			});
		} catch (\Throwable $e) {}
	}
};
