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
		// Tabla cobro: cambiar descuento y pu_mensualidad a 4 decimales
		Schema::table('cobro', function (Blueprint $table) {
			// Cambiar descuento de string a decimal(10,4) para mayor precisión en descuentos prorrateados
			$table->decimal('descuento', 10, 4)->nullable()->change();

			// Cambiar pu_mensualidad de decimal(10,2) a decimal(10,4) para mayor precisión
			$table->decimal('pu_mensualidad', 10, 4)->change();
		});

		// Tabla cobros_detalle_regular: cambiar pu_mensualidad a 4 decimales
		if (Schema::hasTable('cobros_detalle_regular')) {
			Schema::table('cobros_detalle_regular', function (Blueprint $table) {
				$table->decimal('pu_mensualidad', 10, 4)->change();
			});
		}

		// Tabla factura_detalle: cambiar precio_unitario, descuento y subtotal a 4 decimales
		if (Schema::hasTable('factura_detalle')) {
			Schema::table('factura_detalle', function (Blueprint $table) {
				$table->decimal('precio_unitario', 10, 4)->default(0)->change();
				$table->decimal('descuento', 10, 4)->default(0)->change();
				$table->decimal('subtotal', 10, 4)->default(0)->change();
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		// Revertir tabla cobro
		Schema::table('cobro', function (Blueprint $table) {
			$table->string('descuento', 255)->nullable()->change();
			$table->decimal('pu_mensualidad', 10, 2)->change();
		});

		// Revertir tabla cobros_detalle_regular
		if (Schema::hasTable('cobros_detalle_regular')) {
			Schema::table('cobros_detalle_regular', function (Blueprint $table) {
				$table->decimal('pu_mensualidad', 10, 2)->change();
			});
		}

		// Revertir tabla factura_detalle
		if (Schema::hasTable('factura_detalle')) {
			Schema::table('factura_detalle', function (Blueprint $table) {
				$table->decimal('precio_unitario', 10, 2)->default(0)->change();
				$table->decimal('descuento', 10, 2)->default(0)->change();
				$table->decimal('subtotal', 10, 2)->default(0)->change();
			});
		}
	}
};
