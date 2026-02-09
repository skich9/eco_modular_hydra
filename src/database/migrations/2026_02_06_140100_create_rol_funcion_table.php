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
		Schema::create('rol_funcion', function (Blueprint $table) {
			$table->id('id_rol_funcion');
			$table->foreignId('id_rol')->constrained('rol', 'id_rol')->onDelete('cascade');
			$table->foreignId('id_funcion')->constrained('funciones', 'id_funcion')->onDelete('cascade');
			$table->timestamps();
			
			$table->unique(['id_rol', 'id_funcion'], 'unique_rol_funcion');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('rol_funcion');
	}
};
