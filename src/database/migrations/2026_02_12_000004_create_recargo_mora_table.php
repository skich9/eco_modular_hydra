<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Tabla de asignación de mora.
	 * Registra cada mora asignada a una asignación de costo.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_mora')) {
			Schema::create('asignacion_mora', function (Blueprint $table) {
				$table->bigIncrements('id_asignacion_mora');

				// Relación con la asignación de costo (cuota específica)
				$table->unsignedBigInteger('id_asignacion_costo');

				// Relación con la configuración de mora aplicada
				$table->unsignedBigInteger('id_datos_mora_detalle')->nullable();

				// Fechas de mora
				$table->date('fecha_inicio_mora')->comment('Desde cuándo empezó a correr la mora');
				$table->date('fecha_fin_mora')->nullable()->comment('Hasta cuándo corrió la mora (NULL si aún corre)');

				// Monto base y mora
				$table->decimal('monto_base', 10, 2)->comment('Monto de la cuota sobre la cual se calcula la mora');
				$table->decimal('monto_mora', 10, 2)->nullable()->comment('Monto de mora aplicado');
				$table->decimal('monto_descuento', 10, 2)->default(0)->comment('Total de descuentos aplicados');

				// Estado
				$table->enum('estado', ['PENDIENTE', 'PAGADO', 'CONDONADO', 'CANCELADO'])->default('PENDIENTE');

				// Observaciones
				$table->text('observaciones')->nullable();

				$table->timestamps();

				// Foreign keys
				$table->foreign('id_asignacion_costo', 'fk_asignacion_mora_asignacion')
					->references('id_asignacion_costo')->on('asignacion_costos')
					->onDelete('cascade')->onUpdate('cascade');

				$table->foreign('id_datos_mora_detalle', 'fk_asignacion_mora_detalle')
					->references('id_datos_mora_detalle')->on('datos_mora_detalle')
					->onDelete('set null')->onUpdate('cascade');

				// Índices
				$table->index('id_asignacion_costo', 'idx_asignacion_mora_asignacion');
				$table->index('estado', 'idx_asignacion_mora_estado');
				$table->index(['fecha_inicio_mora', 'fecha_fin_mora'], 'idx_asignacion_mora_fechas');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('asignacion_mora');
	}
};
