<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 * Modifica la tabla asignacion_funcion:
	 * - Elimina el campo 'usuario_asig' (no se usa)
	 * - Agrega el campo 'id_asignacion_funcion' como primary key auto-increment
	 *   para tener un historial rastreable de asignaciones
	 */
	public function up()
	{
		// Paso 1: Eliminar las foreign keys que dependen de la primary key
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->dropForeign(['id_usuario']);
			$table->dropForeign(['id_funcion']);
		});

		// Paso 2: Eliminar la clave primaria compuesta existente
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->dropPrimary(['id_usuario', 'id_funcion']);
		});

		// Paso 3: Agregar el nuevo campo id_asignacion_funcion como primary key
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->bigIncrements('id_asignacion_funcion')->first();
		});

		// Paso 4: Eliminar usuario_asig si existe
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			if (Schema::hasColumn('asignacion_funcion', 'usuario_asig')) {
				$table->dropColumn('usuario_asig');
			}
		});

		// Paso 5: Recrear las foreign keys y agregar índices
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->index(['id_usuario', 'id_funcion']);
			$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('cascade');
			$table->foreign('id_funcion')->references('id_funcion')->on('funciones')->onDelete('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down()
	{
		// Eliminar foreign keys e índice
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->dropForeign(['id_usuario']);
			$table->dropForeign(['id_funcion']);
			$table->dropIndex(['id_usuario', 'id_funcion']);
		});

		// Restaurar usuario_asig
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->unsignedBigInteger('usuario_asig')->nullable();
		});

		// Eliminar id_asignacion_funcion
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->dropColumn('id_asignacion_funcion');
		});

		// Restaurar la clave primaria compuesta
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->primary(['id_usuario', 'id_funcion']);
		});

		// Recrear foreign keys
		Schema::table('asignacion_funcion', function (Blueprint $table) {
			$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('cascade');
			$table->foreign('id_funcion')->references('id_funcion')->on('funciones')->onDelete('cascade');
		});
	}
};
