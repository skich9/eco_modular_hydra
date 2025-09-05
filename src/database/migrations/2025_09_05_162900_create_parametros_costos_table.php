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
		if (!Schema::hasTable('parametros_costos')) {
			Schema::create('parametros_costos', function (Blueprint $table) {
				$table->increments('id_parametro_costo');
				$table->string('nombre', 20);
				$table->decimal('valor', 10, 2);
				$table->string('descripcion', 150);
				$table->string('gestion', 30);
				$table->boolean('estado');
				$table->timestamps();

				// Evitar duplicados por parámetro y gestión
				$table->unique(['nombre', 'gestion'], 'uk_param_costos_nombre_gestion');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('parametros_costos');
	}
};
