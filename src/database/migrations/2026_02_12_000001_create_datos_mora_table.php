<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Tabla de configuración de moras por gestión.
	 * Define los parámetros generales de cálculo de mora.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('datos_mora')) {
			Schema::create('datos_mora', function (Blueprint $table) {
				$table->bigIncrements('id_datos_mora');

				// Gestión académica a la que aplica esta configuración
				$table->string('gestion', 30);

				// Tipo de cálculo de mora
				$table->enum('tipo_calculo', ['PORCENTAJE', 'MONTO_FIJO', 'AMBOS'])->default('PORCENTAJE');

				// Monto de mora
				$table->decimal('monto', 10, 2)->nullable()->comment('Monto de mora en Bs');

				// Estado
				$table->boolean('activo')->default(true);

				$table->timestamps();

				// Índices
				$table->unique(['gestion'], 'uk_datos_mora_gestion');
				$table->index('activo', 'idx_datos_mora_activo');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('datos_mora');
	}
};
