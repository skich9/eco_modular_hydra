<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		// Verificar y agregar columnas si no existen
		if (!Schema::hasColumn('funciones', 'codigo')) {
			Schema::table('funciones', function (Blueprint $table) {
				$table->string('codigo', 100)->nullable()->after('id_funcion');
			});
		}

		if (!Schema::hasColumn('funciones', 'modulo')) {
			Schema::table('funciones', function (Blueprint $table) {
				$table->string('modulo', 50)->nullable()->after('descripcion');
			});
		}

		if (!Schema::hasColumn('funciones', 'icono')) {
			Schema::table('funciones', function (Blueprint $table) {
				$table->string('icono', 50)->nullable()->after('modulo');
			});
		}

		// Actualizar registros existentes con códigos únicos basados en nombre
		$funciones = DB::table('funciones')->whereNull('codigo')->orWhere('codigo', '')->get();
		foreach ($funciones as $funcion) {
			$codigo = strtolower(str_replace(' ', '_', $funcion->nombre));
			DB::table('funciones')
				->where('id_funcion', $funcion->id_funcion)
				->update(['codigo' => $codigo]);
		}

		// Agregar constraint unique si no existe
		$indexes = DB::select("SHOW INDEX FROM funciones WHERE Key_name = 'funciones_codigo_unique'");
		if (empty($indexes)) {
			Schema::table('funciones', function (Blueprint $table) {
				$table->string('codigo', 100)->unique()->change();
			});
		}

		// Renombrar columna estado a activo si existe
		if (Schema::hasColumn('funciones', 'estado') && !Schema::hasColumn('funciones', 'activo')) {
			Schema::table('funciones', function (Blueprint $table) {
				$table->renameColumn('estado', 'activo');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('funciones', function (Blueprint $table) {
			$table->dropColumn(['codigo', 'modulo', 'icono']);
			$table->renameColumn('activo', 'estado');
		});
	}
};
