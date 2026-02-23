<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDescuentoDetalleTable extends Migration
{
	public function up()
	{
		if (Schema::hasTable('descuento_detalle')) {
			return;
		}

		Schema::create('descuento_detalle', function (Blueprint $table) {
			$table->bigIncrements('id_descuento_detalle');
			$table->unsignedBigInteger('id_descuento');
			$table->unsignedBigInteger('id_inscripcion')->nullable();
			$table->unsignedBigInteger('id_cuota')->nullable();
			$table->decimal('monto_descuento', 10, 2)->nullable();
			$table->string('cod_Archivo', 255)->nullable();
			$table->text('observaciones')->nullable();
			$table->string('tipo_inscripcion', 100)->nullable();
			$table->string('meses_descuento', 255)->nullable();
			$table->timestamps();

			$table->foreign('id_descuento')->references('id_descuentos')->on('descuentos')->onDelete('restrict');
			$table->foreign('id_inscripcion')->references('cod_inscrip')->on('inscripciones')->onDelete('restrict');
		});
	}

	public function down()
	{
		if (!Schema::hasTable('descuento_detalle')) {
			return;
		}

		Schema::drop('descuento_detalle');
	}
}
