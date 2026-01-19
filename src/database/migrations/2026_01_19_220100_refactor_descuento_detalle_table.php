<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 * Eliminar: id_usuario, turno, semestre, estado, fecha_registro, fecha_solicitud
	 */
	public function up(): void
	{
		// Eliminar foreign key de id_usuario antes de eliminar la columna
		Schema::table('descuento_detalle', function (Blueprint $table) {
			try {
				$table->dropForeign(['id_usuario']);
			} catch (\Throwable $e) {
				// FK puede no existir
			}
		});

		Schema::table('descuento_detalle', function (Blueprint $table) {
			// Eliminar campos
			if (Schema::hasColumn('descuento_detalle', 'id_usuario')) {
				$table->dropColumn('id_usuario');
			}
			if (Schema::hasColumn('descuento_detalle', 'turno')) {
				$table->dropColumn('turno');
			}
			if (Schema::hasColumn('descuento_detalle', 'semestre')) {
				$table->dropColumn('semestre');
			}
			if (Schema::hasColumn('descuento_detalle', 'estado')) {
				$table->dropColumn('estado');
			}
			if (Schema::hasColumn('descuento_detalle', 'fecha_registro')) {
				$table->dropColumn('fecha_registro');
			}
			if (Schema::hasColumn('descuento_detalle', 'fecha_solicitud')) {
				$table->dropColumn('fecha_solicitud');
			}
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('descuento_detalle', function (Blueprint $table) {
			// Restaurar campos eliminados
			if (!Schema::hasColumn('descuento_detalle', 'id_usuario')) {
				$table->unsignedBigInteger('id_usuario')->nullable()->after('id_descuento');
			}
			if (!Schema::hasColumn('descuento_detalle', 'turno')) {
				$table->string('turno', 100)->nullable()->after('tipo_inscripcion');
			}
			if (!Schema::hasColumn('descuento_detalle', 'semestre')) {
				$table->string('semestre', 100)->nullable()->after('turno');
			}
			if (!Schema::hasColumn('descuento_detalle', 'estado')) {
				$table->boolean('estado')->nullable()->after('meses_descuento');
			}
			if (!Schema::hasColumn('descuento_detalle', 'fecha_registro')) {
				$table->timestamp('fecha_registro')->nullable()->after('cod_Archivo');
			}
			if (!Schema::hasColumn('descuento_detalle', 'fecha_solicitud')) {
				$table->date('fecha_solicitud')->nullable()->after('fecha_registro');
			}
		});

		// Restaurar foreign key
		Schema::table('descuento_detalle', function (Blueprint $table) {
			if (Schema::hasTable('usuarios') && Schema::hasColumn('descuento_detalle', 'id_usuario')) {
				try {
					$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict');
				} catch (\Throwable $e) {
					// FK puede ya existir
				}
			}
		});
	}
};
