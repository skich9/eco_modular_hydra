<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->boolean('activo')->default(true)->after('fecha_fin');
			$table->text('observaciones')->nullable()->after('activo');
			$table->foreignId('asignado_por')->nullable()->after('observaciones')->constrained('usuarios', 'id_usuario')->onDelete('set null');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->dropForeign(['asignado_por']);
			$table->dropColumn(['activo', 'observaciones', 'asignado_por']);
		});
	}
};
