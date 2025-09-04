<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('razon_social', function (Blueprint $table) {
			$table->text('razon_social')->nullable();
			$table->string('nit', 50);
			$table->string('tipo', 255);
			$table->tinyInteger('id_tipo_doc_identidad')->nullable();
			$table->string('complemento', 10)->nullable();
			$table->timestamps();

			$table->primary(['nit', 'tipo']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('razon_social');
	}
};
