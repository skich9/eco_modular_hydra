<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('pensums')) {
			Schema::table('pensums', function (Blueprint $table) {
				if (!Schema::hasColumn('pensums', 'activo')) {
					$table->boolean('activo')->nullable()->after('nivel');
				}
			});
			// Copiar datos desde estado si existe
			try { DB::statement('UPDATE `pensums` SET `activo` = `estado` WHERE `activo` IS NULL'); } catch (\Throwable $e) {}
			// Eliminar columna estado si existe
			Schema::table('pensums', function (Blueprint $table) {
				if (Schema::hasColumn('pensums', 'estado')) {
					$table->dropColumn('estado');
				}
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('pensums')) {
			Schema::table('pensums', function (Blueprint $table) {
				if (!Schema::hasColumn('pensums', 'estado')) {
					$table->boolean('estado')->nullable()->after('nivel');
				}
			});
			try { DB::statement('UPDATE `pensums` SET `estado` = `activo` WHERE `estado` IS NULL'); } catch (\Throwable $e) {}
			Schema::table('pensums', function (Blueprint $table) {
				if (Schema::hasColumn('pensums', 'activo')) {
					$table->dropColumn('activo');
				}
			});
		}
	}
};
