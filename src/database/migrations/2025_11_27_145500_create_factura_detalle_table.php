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
		Schema::create('factura_detalle', function (Blueprint $table) {
			$table->integer('anio');
			$table->integer('nro_factura');
			$table->integer('id_detalle');
			$table->integer('codigo_sin')->default(99100);
			$table->string('codigo', 100)->nullable();
			$table->string('descripcion', 500);
			$table->decimal('cantidad', 10, 2)->default(1);
			$table->integer('unidad_medida')->default(58);
			$table->decimal('precio_unitario', 10, 2)->default(0);
			$table->decimal('descuento', 10, 2)->default(0);
			$table->decimal('subtotal', 10, 2)->default(0);
			
			// Clave primaria compuesta
			$table->primary(['anio', 'nro_factura', 'id_detalle']);
			
			// Índice para búsquedas por factura
			$table->index(['anio', 'nro_factura']);
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('factura_detalle');
	}
};
