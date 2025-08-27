<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('def_descuentos', function (Blueprint $table) {
			$table->bigIncrements('cod_descuento');
			$table->string('nombre_descuento', 255);
			$table->text('descripcion')->nullable();
			$table->integer('monto');
			$table->boolean('porcentaje');
			$table->boolean('estado');

			$table->unique('nombre_descuento');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('def_descuentos');
	}
};
