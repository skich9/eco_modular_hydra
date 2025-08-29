<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('cuotas', function (Blueprint $table) {
			$table->bigIncrements('id_cuota');
			$table->string('nombre');
			$table->text('descripcion')->nullable();
			$table->decimal('monto', 10, 2);
			$table->date('fecha_vencimiento')->nullable();
			$table->string('estado')->nullable();
			$table->string('tipo')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cuotas');
	}
};
