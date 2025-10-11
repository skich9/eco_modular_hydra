<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		// Quitar auto-increment de nro_recibo (usaremos secuencia propia)
		Schema::table('recibo', function (Blueprint $table) {
			$table->integer('nro_recibo', unsigned: true)->change();
		});
	}

	public function down(): void
	{
		// Restaurar auto-increment si fuese necesario (MySQL requiere ser parte de una key)
		Schema::table('recibo', function (Blueprint $table) {
			$table->integer('nro_recibo', unsigned: true)->autoIncrement()->change();
		});
	}
};
