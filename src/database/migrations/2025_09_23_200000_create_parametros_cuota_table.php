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
		if (!Schema::hasTable('parametros_cuota')) {
			Schema::create('parametros_cuota', function (Blueprint $table) {
				// Id autoincremental
				$table->integer('id_parametro_cuota')->autoIncrement();
				// Columnas segun el SQL de referencia
				$table->string('nombre_cuota', 50);
				$table->date('fecha_venecimiento');
				$table->boolean('activo');
				$table->timestamps(); // created_at y updated_at pueden ser null por defecto

				// Clave primaria compuesta (como en el SQL de referencia)
				$table->primary(['id_parametro_cuota', 'nombre_cuota'], 'pk_parametros_cuota');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('parametros_cuota');
	}
};
