<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('materia')) {
			return; // tabla no existe en este entorno
		}

		if (!Schema::hasColumn('materia', 'nivel_materia')) {
			Schema::table('materia', function (Blueprint $table) {
				// Agregar inicialmente como nullable para no fallar con datos existentes
				$table->string('nivel_materia', 50)->nullable()->after('nombre_material_oficial');
			});

			// Establecer un valor por defecto temporal en filas existentes si está NULL
			DB::table('materia')->whereNull('nivel_materia')->update(['nivel_materia' => '1']);

			// Intentar forzar NOT NULL (requiere doctrine/dbal para change())
			try {
				Schema::table('materia', function (Blueprint $table) {
					$table->string('nivel_materia', 50)->nullable(false)->change();
				});
			} catch (\Throwable $e) {
				// En caso de no tener DBAL, al menos dejamos el campo creado y con valor en datos existentes
			}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('materia') && Schema::hasColumn('materia', 'nivel_materia')) {
			Schema::table('materia', function (Blueprint $table) {
				$table->dropColumn('nivel_materia');
			});
		}
	}
};
