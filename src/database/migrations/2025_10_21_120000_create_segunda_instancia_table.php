<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('segunda_instancia', function (Blueprint $table) {
			$table->unsignedBigInteger('cod_inscrip');
			$table->integer('num_instancia');
			$table->integer('num_pago_ins');
			$table->integer('num_factura')->nullable();
			$table->integer('num_recibo')->nullable();
			$table->timestamp('fecha_pago');
			$table->decimal('monto', 10, 2);
			$table->boolean('pago_completo');
			$table->string('observaciones', 150)->nullable();
			$table->unsignedBigInteger('usuario');
			$table->string('materia', 255)->nullable();
			$table->char('valido', 1)->nullable();
			$table->timestamps();
			$table->primary(['cod_inscrip', 'num_instancia', 'num_pago_ins']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('segunda_instancia');
	}
};
