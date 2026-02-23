<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDescuentosTable extends Migration
{
	public function up()
	{
		if (Schema::hasTable('descuentos')) {
			return;
		}

		Schema::create('descuentos', function (Blueprint $table) {
			$table->bigIncrements('id_descuentos');
			$table->unsignedBigInteger('cod_ceta');
			$table->string('cod_pensum', 50);
			$table->unsignedBigInteger('cod_inscrip');
			$table->unsignedBigInteger('cod_beca')->nullable();
			$table->unsignedBigInteger('id_usuario');
			$table->string('nombre', 255);
			$table->text('observaciones')->nullable();
			$table->string('tipo', 100)->nullable();
			$table->boolean('estado')->nullable();
			$table->timestamp('fecha_registro')->nullable();
			$table->date('fecha_solicitud')->nullable();
			$table->timestamps();

			$table->foreign('cod_ceta')->references('cod_ceta')->on('estudiantes')->onDelete('restrict');
			$table->foreign('cod_pensum')->references('cod_pensum')->on('pensums')->onDelete('restrict');
			$table->foreign('cod_inscrip')->references('cod_inscrip')->on('inscripciones')->onDelete('restrict');
			$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict');
			$table->foreign('cod_beca')->references('cod_beca')->on('def_descuentos_beca')->onDelete('restrict');
		});
	}

	public function down()
	{
		if (!Schema::hasTable('descuentos')) {
			return;
		}

		Schema::drop('descuentos');
	}
}
