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
		// Para evitar conflictos con estructuras previas, se recrea la tabla
		Schema::dropIfExists('parametros_costos');

		Schema::create('parametros_costos', function (Blueprint $table) {
			$table->integer('id_parametro_costo')->autoIncrement();
			$table->string('nombre_costo', 50);
			$table->string('nombre_oficial', 50)->nullable();
			$table->string('descripcion', 150)->nullable();
			$table->boolean('activo');
			$table->timestamps(); // created_at / updated_at NULL por defecto

			$table->primary(['id_parametro_costo', 'nombre_costo'], 'pk_parametros_costos');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('parametros_costos');
	}
};
