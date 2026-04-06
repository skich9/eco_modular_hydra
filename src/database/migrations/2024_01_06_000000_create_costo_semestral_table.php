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
		if (!Schema::hasTable('costo_semestral')) {
			Schema::create('costo_semestral', function (Blueprint $table) {
				$table->bigIncrements('id_costo_semestral');
				$table->string('cod_pensum', 50);
				$table->string('gestion', 30);
				$table->string('semestre', 30);
				$table->string('turno', 150)->default('REGULAR');
				$table->decimal('monto_semestre', 10, 2);
				$table->string('tipo_costo', 50)->nullable();
				$table->boolean('costo_fijo')->default(false);
				$table->decimal('valor_credito', 10, 2)->default(0);
				$table->unsignedBigInteger('id_usuario');
				$table->timestamps();

				// Índice normal (no único) para búsquedas
				$table->index(['cod_pensum', 'gestion', 'semestre', 'tipo_costo', 'turno'], 'idx_costo_semestral_busqueda');

				$table->foreign('cod_pensum')
					->references('cod_pensum')
					->on('pensums')
					->onDelete('restrict')
					->onUpdate('restrict');

				$table->foreign('gestion')
					->references('gestion')
					->on('gestion')
					->onDelete('restrict')
					->onUpdate('restrict');

				$table->foreign('id_usuario')
					->references('id_usuario')
					->on('usuarios')
					->onDelete('restrict')
					->onUpdate('restrict');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('costo_semestral');
	}
};

