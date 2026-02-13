<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Tabla de configuración detallada de moras por semestre/cuota.
	 * Permite configurar diferentes parámetros según semestre, número de cuota y fechas.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('datos_mora_detalle')) {
			Schema::create('datos_mora_detalle', function (Blueprint $table) {
				$table->bigIncrements('id_datos_mora_detalle');

				// Relación con configuración general de mora
				$table->unsignedBigInteger('id_datos_mora');

				// Relación con cuota específica
				$table->unsignedBigInteger('id_cuota')->nullable()->comment('ID de la cuota específica');

				// Contexto académico
				$table->string('semestre', 30)->comment('1, 2, 3, 4, 5, 6');

				// Monto de mora
				$table->decimal('monto', 10, 2)->nullable()->comment('Monto específico para este semestre/cuota');

				// Vigencia temporal de esta configuración
				$table->date('fecha_inicio')->nullable()->comment('Desde cuándo aplica esta configuración');
				$table->date('fecha_fin')->nullable()->comment('Hasta cuándo aplica esta configuración');

				// Estado
				$table->boolean('activo')->default(true);

				$table->timestamps();

				// Foreign keys
				$table->foreign('id_datos_mora', 'fk_mora_detalle_datos_mora')
					->references('id_datos_mora')->on('datos_mora')
					->onDelete('cascade')->onUpdate('cascade');

				$table->foreign('id_cuota', 'fk_mora_detalle_cuota')
					->references('id_cuota')->on('cuotas')
					->onDelete('cascade')->onUpdate('cascade');

				// Índices
				$table->unique(['id_datos_mora', 'semestre', 'id_cuota'], 'uk_mora_detalle_semestre_cuota');
				$table->index('activo', 'idx_mora_detalle_activo');
				$table->index(['fecha_inicio', 'fecha_fin'], 'idx_mora_detalle_vigencia');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('datos_mora_detalle');
	}
};
