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
            $fkExists = DB::select("
				SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignacion_funcion'
				AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'asignado_por'
			");
			if (!empty($fkExists)) {
				$table->dropForeign('asignado_por');
			}
			// $table->dropForeign(['asignado_por']);
			$table->dropColumn(['activo', 'observaciones', 'asignado_por']);
		});
	}
};
