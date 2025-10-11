<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('factura')) {
			Schema::create('factura', function (Blueprint $table) {
				$table->integer('nro_factura');
				$table->integer('anio');
				$table->string('tipo', 1); // C=Computarizada, M=Manual
				$table->integer('codigo_sucursal')->default(0);
				$table->string('codigo_punto_venta', 25)->default('0');
				$table->timestamp('fecha_emision');
				$table->bigInteger('cod_ceta')->nullable();
				$table->unsignedBigInteger('id_usuario');
				$table->string('id_forma_cobro', 255);
				$table->decimal('monto_total', 10, 2);
				$table->string('codigo_cufd', 100)->nullable(); // para computarizada
				$table->string('cuf', 150)->nullable(); // identificador fiscal
				$table->string('codigo_cafc', 25)->nullable(); // para manual
				$table->string('pdf_path', 255)->nullable();
				$table->string('qr_path', 255)->nullable();
				$table->string('estado', 50)->default('VIGENTE');
				$table->timestamps();

				$table->primary(['nro_factura','anio','codigo_sucursal','codigo_punto_venta'], 'pk_factura_compuesta');
				$table->index(['codigo_cufd'], 'idx_factura_cufd');
				$table->index(['codigo_cafc'], 'idx_factura_cafc');
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('factura');
	}
};
