<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 * Agregar: fecha_registro, fecha_solicitud
	 * Eliminar: porcentaje, cod_descuento
	 */
	public function up(): void
	{
		Schema::table('descuentos', function (Blueprint $table) {
			// Agregar nuevos campos
			if (!Schema::hasColumn('descuentos', 'fecha_registro')) {
				$table->timestamp('fecha_registro')->nullable()->after('observaciones');
			}
			if (!Schema::hasColumn('descuentos', 'fecha_solicitud')) {
				$table->date('fecha_solicitud')->nullable()->after('fecha_registro');
			}
		});

		// Eliminar foreign key de cod_descuento antes de eliminar la columna
		Schema::table('descuentos', function (Blueprint $table) {
			try {
				$table->dropForeign(['cod_descuento']);
			} catch (\Throwable $e) {
				// FK puede no existir
			}
		});

		Schema::table('descuentos', function (Blueprint $table) {
			// Eliminar campos
			if (Schema::hasColumn('descuentos', 'porcentaje')) {
				$table->dropColumn('porcentaje');
			}
			if (Schema::hasColumn('descuentos', 'cod_descuento')) {
				$table->dropColumn('cod_descuento');
			}
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('descuentos', function (Blueprint $table) {
			// Restaurar campos eliminados
			if (!Schema::hasColumn('descuentos', 'porcentaje')) {
				$table->decimal('porcentaje', 10, 2)->nullable()->after('observaciones');
			}
			if (!Schema::hasColumn('descuentos', 'cod_descuento')) {
				$table->unsignedBigInteger('cod_descuento')->nullable()->after('id_descuentos');
			}
		});

		// Restaurar foreign key
		Schema::table('descuentos', function (Blueprint $table) {
			if (Schema::hasTable('def_descuentos') && Schema::hasColumn('descuentos', 'cod_descuento')) {
				try {
					$table->foreign('cod_descuento')->references('cod_descuento')->on('def_descuentos')->onDelete('restrict');
				} catch (\Throwable $e) {
					// FK puede ya existir
				}
			}
		});

		Schema::table('descuentos', function (Blueprint $table) {
			// Eliminar campos agregados
			if (Schema::hasColumn('descuentos', 'fecha_solicitud')) {
				$table->dropColumn('fecha_solicitud');
			}
			if (Schema::hasColumn('descuentos', 'fecha_registro')) {
				$table->dropColumn('fecha_registro');
			}
		});
	}
};
