<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('gestion')) {
			Schema::table('gestion', function (Blueprint $table) {
				if (!Schema::hasColumn('gestion', 'activo')) {
					$table->boolean('activo')->nullable()->after('fecha_graduacion');
				}
			});
			try { DB::statement('UPDATE `gestion` SET `activo` = `estado` WHERE `activo` IS NULL'); } catch (\Throwable $e) {}
			Schema::table('gestion', function (Blueprint $table) {
				if (Schema::hasColumn('gestion', 'estado')) {
					$table->dropColumn('estado');
				}
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('gestion')) {
			Schema::table('gestion', function (Blueprint $table) {
				if (!Schema::hasColumn('gestion', 'estado')) {
					$table->boolean('estado')->nullable()->after('fecha_graduacion');
				}
			});
			try { DB::statement('UPDATE `gestion` SET `estado` = `activo` WHERE `estado` IS NULL'); } catch (\Throwable $e) {}
			Schema::table('gestion', function (Blueprint $table) {
				if (Schema::hasColumn('gestion', 'activo')) {
					$table->dropColumn('activo');
				}
			});
		}
	}
};
