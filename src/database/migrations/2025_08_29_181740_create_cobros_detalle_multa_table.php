<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('cobros_detalle_multa', function (Blueprint $table) {
			$table->integer('nro_cobro')->primary();
			$table->decimal('pu_multa', 10, 2);
			$table->integer('dias_multa');
			$table->timestamps();

			$table->foreign('nro_cobro')->references('nro_cobro')->on('cobro')->onDelete('restrict')->onUpdate('restrict');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cobros_detalle_multa');
	}
};
