<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('recibo')) {
			Schema::create('recibo', function (Blueprint $table) {
				$table->integer('nro_recibo', unsigned: true)->autoIncrement();
				$table->integer('anio');
				$table->string('id_usuario', 200);
				$table->float('descuento_adicional', 8)->nullable();
				$table->string('id_forma_cobro', 255); // alineado con formas_cobro.id_forma_cobro
				$table->string('complemento', 255)->nullable();
				$table->integer('cod_tipo_doc_identidad');
				$table->decimal('monto_gift_card', 10, 2)->nullable();
				$table->string('num_gift_card', 50)->nullable();
				$table->integer('tipo_emision')->nullable();
				$table->integer('codigo_excepcion')->nullable();
				$table->integer('codigo_doc_sector')->nullable();
				$table->boolean('tiene_reposicion')->nullable();
				$table->string('periodo_facturado', 50)->nullable();
				// Campos añadidos según necesidades Hydra
				$table->bigInteger('cod_ceta')->nullable();
				$table->string('estado', 50)->default('VIGENTE');
				$table->decimal('monto_total', 10, 2)->default(0);
				$table->timestamps();

				$table->primary(['nro_recibo', 'anio']);
				$table->index('id_usuario', 'idx_recibo_id_usuario');
				$table->index('cod_ceta', 'idx_recibo_cod_ceta');

				$table->foreign('id_forma_cobro')->references('id_forma_cobro')->on('formas_cobro')->onUpdate('restrict')->onDelete('restrict');
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('recibo');
	}
};
