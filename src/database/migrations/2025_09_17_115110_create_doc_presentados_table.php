<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('doc_presentados')) {
			Schema::create('doc_presentados', function (Blueprint $table) {
				// Nota: no usamos increments para poder establecer el ID desde SGA (cod_documento)
				$table->integer('id_doc_presentados');
				$table->unsignedBigInteger('cod_ceta');
				$table->string('numero_doc', 150)->nullable();
				$table->string('nombre_doc', 100);
				$table->string('procedencia', 150)->nullable();
				$table->boolean('entregado')->nullable();
				$table->primary(['id_doc_presentados', 'cod_ceta']);
				$table->index('cod_ceta');
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('doc_presentados');
	}
};
