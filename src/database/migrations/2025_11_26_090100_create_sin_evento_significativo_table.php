<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSinEventoSignificativoTable extends Migration
{
	public function up()
	{
		if (!Schema::hasTable('sin_evento_significativo')) {
			Schema::create('sin_evento_significativo', function (Blueprint $table) {
				$table->integer('id_evento')->primary();
				$table->integer('codigo_recepcion');
				$table->timestamp('fecha_inicio');
				$table->timestamp('fecha_fin');
				$table->integer('codigo_evento');
				$table->integer('codigo_sucursal')->nullable();
				$table->string('codigo_punto_venta', 25)->nullable();
			});
		}
	}

	public function down()
	{
		Schema::dropIfExists('sin_evento_significativo');
	}
}

