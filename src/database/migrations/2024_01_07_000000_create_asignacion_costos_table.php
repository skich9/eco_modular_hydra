<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			Schema::create('asignacion_costos', function (Blueprint $table) {
			$table->bigInteger('id_asignacion_costo')->autoIncrement();
			$table->string('cod_pensum', 50);
			$table->unsignedBigInteger('cod_inscrip');
			$table->decimal('monto', 10, 2);
			$table->text('observaciones')->nullable();
			$table->boolean('estado')->nullable();
			$table->bigInteger('id_costo_semestral');
			$table->string('id_descuentoDetalle', 255)->nullable();
			$table->integer('id_prorroga')->nullable();
			$table->integer('id_compromisos')->nullable();
			$table->timestamps();
			
			// Definimos la clave primaria solo con id_asignacion_costo
			// y agregamos un índice único para la combinación de campos
			$table->unique(['cod_pensum', 'cod_inscrip', 'id_costo_semestral'], 'asignacion_costos_unique');
			
			$table->foreign('cod_pensum')
				  ->references('cod_pensum')
				  ->on('pensums')
				  ->onDelete('restrict')
				  ->onUpdate('restrict');
				  
			$table->foreign(['id_costo_semestral', 'cod_pensum'])
				  ->references(['id_costo_semestral', 'cod_pensum'])
				  ->on('costo_semestral')
				  ->onDelete('restrict')
				  ->onUpdate('restrict');
				  
			$table->foreign('cod_inscrip')
				  ->references('cod_inscrip')
				  ->on('inscripciones')
				  ->onDelete('restrict')
				  ->onUpdate('restrict');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('asignacion_costos');
	}
};
