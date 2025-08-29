<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('cobros_detalle_regular', function (Blueprint $table) {
			$table->integer('nro_cobro')->primary();
			$table->unsignedBigInteger('cod_inscrip');
			$table->decimal('pu_mensualidad', 10, 2);
			$table->string('turno', 100);
			$table->timestamps();

			$table->foreign('nro_cobro')->references('nro_cobro')->on('cobro')->onDelete('restrict')->onUpdate('restrict');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cobros_detalle_regular');
	}
};
