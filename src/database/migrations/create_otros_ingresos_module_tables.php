<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('tipo_otro_ingreso')) {
			Schema::create('tipo_otro_ingreso', function (Blueprint $table) {
				$table->id();
				$table->string('cod_tipo_ingreso', 40)->unique();
				$table->string('nom_tipo_ingreso', 150);
				$table->text('descripcion_tipo_ingreso')->nullable();
				$table->timestamps();
			});
		}

		if (!Schema::hasTable('otros_ingresos')) {
			Schema::create('otros_ingresos', function (Blueprint $table) {
				$table->id();
				$table->unsignedBigInteger('num_factura')->default(0);
				$table->unsignedBigInteger('num_recibo')->default(0);
				$table->string('nit', 50);
				$table->dateTime('fecha');
				$table->string('razon_social', 255)->nullable();
				$table->string('autorizacion', 100)->default('');
				$table->string('codigo_control', 80)->nullable();
				$table->decimal('monto', 14, 2);
				$table->string('valido', 1)->default('S');
				$table->string('usuario', 100);
				$table->text('concepto')->nullable();
				$table->text('observaciones')->nullable();
				$table->string('cod_pensum', 50);
				$table->string('gestion', 30);
				$table->decimal('subtotal', 14, 2)->default(0);
				$table->decimal('descuento', 14, 2)->default(0);
				$table->string('code_tipo_pago', 10)->nullable();
				$table->string('tipo_ingreso', 150)->nullable();
				$table->string('cod_tipo_ingreso', 40)->nullable();
				$table->char('factura_recibo', 1)->nullable();
				$table->boolean('es_computarizada')->nullable();
				$table->timestamps();
				$table->index(['num_factura', 'autorizacion'], 'idx_oi_fact_aut');
				$table->index('num_recibo', 'idx_oi_recibo');
			});
		}

		if (!Schema::hasTable('otros_ingresos_detalle')) {
			Schema::create('otros_ingresos_detalle', function (Blueprint $table) {
				$table->id();
				$table->foreignId('otro_ingreso_id')->constrained('otros_ingresos')->cascadeOnDelete();
				$table->string('cta_banco', 80)->nullable();
				$table->string('nro_deposito', 80)->nullable();
				$table->date('fecha_deposito')->nullable();
				$table->date('fecha_ini')->nullable();
				$table->date('fecha_fin')->nullable();
				$table->unsignedBigInteger('nro_orden')->nullable();
				$table->string('concepto_alquiler', 200)->nullable();
				$table->timestamps();
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('otros_ingresos_detalle');
		Schema::dropIfExists('otros_ingresos');
		Schema::dropIfExists('tipo_otro_ingreso');
	}
};
