<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		// Verificar si la tabla existe
		if (Schema::hasTable('sin_recepcion_paquete_factura')) {
			// Modificar la columna id_recepcion para que sea autoincremental
			Schema::table('sin_recepcion_paquete_factura', function (Blueprint $table) {
				$table->bigIncrements('id_recepcion')->change();
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('sin_recepcion_paquete_factura')) {
			Schema::table('sin_recepcion_paquete_factura', function (Blueprint $table) {
				$table->integer('id_recepcion')->change();
			});
		}
	}
};
