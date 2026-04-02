<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea tablas `razon_social` creadas manualmente o en entornos viejos con el esquema esperado por la API.
 * Evita SQLSTATE 42S22 (columna inexistente) al insertar/actualizar.
 */
return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('razon_social')) {
			return;
		}

		Schema::table('razon_social', function (Blueprint $table) {
			if (!Schema::hasColumn('razon_social', 'id_tipo_doc_identidad')) {
				$table->tinyInteger('id_tipo_doc_identidad')->nullable();
			}
			if (!Schema::hasColumn('razon_social', 'complemento')) {
				$table->string('complemento', 10)->nullable();
			}
			if (!Schema::hasColumn('razon_social', 'created_at')) {
				$table->timestamp('created_at')->nullable();
			}
			if (!Schema::hasColumn('razon_social', 'updated_at')) {
				$table->timestamp('updated_at')->nullable();
			}
		});
	}

	public function down(): void
	{
		// No eliminar columnas: podrían tener datos.
	}
};
