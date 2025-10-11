<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('reinicio_secuencia', function (Blueprint $table) {
			$table->string('nombre_secuencia');
			$table->string('anio', 4);
			$table->string('mes', 2);
			$table->timestamps();
			$table->unique(['nombre_secuencia', 'anio', 'mes'], 'ux_reinicio_secuencia');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('reinicio_secuencia');
	}
};
