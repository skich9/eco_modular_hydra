<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('cuotas', function (Blueprint $table) {
			if (!Schema::hasColumn('cuotas', 'gestion')) {
				$table->string('gestion', 30)->nullable()->after('id_cuota');
			}
			if (!Schema::hasColumn('cuotas', 'cod_pensum')) {
				$table->string('cod_pensum', 50)->nullable()->after('gestion');
			}
			if (!Schema::hasColumn('cuotas', 'semestre')) {
				$table->string('semestre', 30)->nullable()->after('cod_pensum');
			}
			if (!Schema::hasColumn('cuotas', 'turno')) {
				$table->string('turno', 150)->nullable()->after('semestre');
			}
			if (!Schema::hasColumn('cuotas', 'activo')) {
				$table->boolean('activo')->default(true)->after('fecha_vencimiento');
			}
			if (Schema::hasColumn('cuotas', 'estado')) {
				$table->dropColumn('estado');
			}
		});
	}

	public function down(): void
	{
		Schema::table('cuotas', function (Blueprint $table) {
			if (Schema::hasColumn('cuotas', 'turno')) {
				$table->dropColumn('turno');
			}
			if (Schema::hasColumn('cuotas', 'semestre')) {
				$table->dropColumn('semestre');
			}
			if (Schema::hasColumn('cuotas', 'cod_pensum')) {
				$table->dropColumn('cod_pensum');
			}
			if (Schema::hasColumn('cuotas', 'gestion')) {
				$table->dropColumn('gestion');
			}
			if (Schema::hasColumn('cuotas', 'activo')) {
				$table->dropColumn('activo');
			}
			// Restaurar 'estado' si no existe
			if (!Schema::hasColumn('cuotas', 'estado')) {
				$table->string('estado')->nullable();
			}
		});
	}
};
