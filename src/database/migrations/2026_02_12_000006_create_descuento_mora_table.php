<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Tabla de descuentos sobre moras.
	 * Permite aplicar descuentos totales o parciales sobre el monto de mora calculado.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('descuento_mora')) {
			Schema::create('descuento_mora', function (Blueprint $table) {
				$table->bigIncrements('id_descuento_mora');

				// Relación con la asignación de mora
				$table->unsignedBigInteger('id_asignacion_mora');

				// Porcentaje (true = es porcentaje, false = es monto fijo)
				$table->boolean('porcentaje')->default(true)->comment('true = porcentaje, false = monto fijo');

				// Porcentaje de descuento (ej: 0.50 = 50%)
				$table->decimal('porcentaje_descuento', 5, 4)->nullable()->comment('Porcentaje de descuento (0.50 = 50%)');

				// Monto fijo de descuento
				$table->decimal('monto_descuento', 10, 2)->nullable()->comment('Monto fijo de descuento en Bs');

				// Observaciones
				$table->text('observaciones')->nullable();

				$table->timestamps();

				// Foreign keys
				$table->foreign('id_asignacion_mora', 'fk_descuento_mora_asignacion')
					->references('id_asignacion_mora')->on('asignacion_mora')
					->onDelete('cascade')->onUpdate('cascade');

				// Índices
				$table->index('id_asignacion_mora', 'idx_descuento_mora_asignacion');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('descuento_mora');
	}
};
