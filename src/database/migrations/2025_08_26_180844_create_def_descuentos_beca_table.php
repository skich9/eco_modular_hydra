<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('def_descuentos_beca', function (Blueprint $table) {
			$table->bigIncrements('cod_beca');
			$table->string('nombre_beca', 255);
			$table->text('descripcion')->nullable();
			$table->integer('monto');
			$table->boolean('porcentaje');
			$table->boolean('estado');

			$table->unique('nombre_beca');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('def_descuentos_beca');
	}
};
