<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Crea la tabla prorrogas_mora para el sistema de moras.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('prorrogas_mora')) {
			Schema::create('prorrogas_mora', function (Blueprint $table) {
				$table->bigIncrements('id_prorroga_mora');

				// Usuario que solicita la prórroga
				$table->unsignedBigInteger('id_usuario');

				// Estudiante
				$table->unsignedBigInteger('cod_ceta');

				// Asignación de costo (cuota específica)
				$table->unsignedBigInteger('id_asignacion_costo');

				// Fechas de la prórroga
				$table->date('fecha_inicio_prorroga')->comment('Desde cuándo se pausa la mora');
				$table->date('fecha_fin_prorroga')->comment('Hasta cuándo está pausada la mora');

				$table->timestamps();

				// Foreign keys
				$table->foreign('id_usuario', 'fk_prorrogas_mora_usuario')
					->references('id_usuario')->on('usuarios')
					->onDelete('restrict')->onUpdate('cascade');

				$table->foreign('cod_ceta', 'fk_prorrogas_mora_estudiante')
					->references('cod_ceta')->on('estudiantes')
					->onDelete('restrict')->onUpdate('cascade');

				$table->foreign('id_asignacion_costo', 'fk_prorrogas_mora_asignacion')
					->references('id_asignacion_costo')->on('asignacion_costos')
					->onDelete('cascade')->onUpdate('cascade');

				// Índices
				$table->index('id_asignacion_costo', 'idx_prorrogas_mora_asignacion');
				$table->index('cod_ceta', 'idx_prorrogas_mora_estudiante');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('prorrogas_mora');
	}
};
