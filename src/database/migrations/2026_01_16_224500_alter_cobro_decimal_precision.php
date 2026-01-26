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
		if (Schema::hasColumn('cobro', 'descuento')) {
			$columnType = Schema::getColumnType('cobro', 'descuento');
			if ($columnType !== 'decimal') {
				Schema::table('cobro', function (Blueprint $table) {
					$table->decimal('descuento', 10, 4)->nullable()->change();
				});
			}
		}

		if (Schema::hasColumn('cobro', 'pu_mensualidad')) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->decimal('pu_mensualidad', 10, 4)->change();
			});
		}

		if (Schema::hasTable('cobros_detalle_regular') && Schema::hasColumn('cobros_detalle_regular', 'pu_mensualidad')) {
			Schema::table('cobros_detalle_regular', function (Blueprint $table) {
				$table->decimal('pu_mensualidad', 10, 4)->change();
			});
		}

		if (Schema::hasTable('factura_detalle')) {
			if (Schema::hasColumn('factura_detalle', 'precio_unitario')) {
				Schema::table('factura_detalle', function (Blueprint $table) {
					$table->decimal('precio_unitario', 10, 4)->default(0)->change();
				});
			}
			if (Schema::hasColumn('factura_detalle', 'descuento')) {
				Schema::table('factura_detalle', function (Blueprint $table) {
					$table->decimal('descuento', 10, 4)->default(0)->change();
				});
			}
			if (Schema::hasColumn('factura_detalle', 'subtotal')) {
				Schema::table('factura_detalle', function (Blueprint $table) {
					$table->decimal('subtotal', 10, 4)->default(0)->change();
				});
			}
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
