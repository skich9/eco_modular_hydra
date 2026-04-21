<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('nota_traspaso', function (Blueprint $table) {
			$table->integer('anio');
			$table->integer('correlativo');
			$table->string('usuario', 100);
			$table->unsignedBigInteger('cod_ceta');
			$table->decimal('monto', 8, 2);
			$table->text('concepto');
			$table->text('tipo')->nullable();
			$table->text('cuota_origen')->nullable();
			$table->text('documento_origen')->nullable();
			$table->text('fecha_origen')->nullable();
			$table->text('carrera_origen')->nullable();
			$table->timestamp('fecha_nota', 6);
			$table->text('cuota_destino')->nullable();
			$table->string('prefijo_carrera', 1)->default('E');
			$table->text('nro_recibo');
			$table->text('nro_factura')->nullable();
			$table->text('observacion')->nullable();
			$table->text('gestion_origen')->nullable();
			$table->text('est_origen')->nullable();
			$table->string('gestion_destino', 6)->nullable();
			$table->primary(['anio', 'correlativo']);
			$table->index('cod_ceta');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('nota_traspaso');
	}
};
