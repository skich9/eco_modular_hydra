<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 *
	 */
	public function up(): void
	{
		// Solo crear si no existe (idempotente)
		if (!Schema::hasTable('tipo_cobro')) {
			Schema::create('tipo_cobro', function (Blueprint $table) {
				$table->string('cod_tipo_cobro', 50)->primary();
				$table->string('nombre_tipo_cobro', 255);
				$table->text('descripcion')->nullable();
				$table->boolean('activo')->default(true);
				$table->timestamps();

				$table->index('nombre_tipo_cobro');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		// No eliminar aquí, la migración oficial (2025_12_24_081600) lo hará
		// Esto evita conflictos al hacer rollback
	}
};
