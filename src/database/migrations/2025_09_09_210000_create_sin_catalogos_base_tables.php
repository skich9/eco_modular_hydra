<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		// sin_tipo_moneda
		if (!Schema::hasTable('sin_tipo_moneda')) {
			Schema::create('sin_tipo_moneda', function (Blueprint $table) {
				$table->integer('codigo_clasificador')->primary();
				$table->string('descripcion', 50)->nullable();
			});
		}

		// sin_tipo_emision
		if (!Schema::hasTable('sin_tipo_emision')) {
			Schema::create('sin_tipo_emision', function (Blueprint $table) {
				$table->integer('codigo_id')->primary();
				$table->string('descripcion', 100)->nullable();
			});
		}

		// sin_list_mensajes
		if (!Schema::hasTable('sin_list_mensajes')) {
			Schema::create('sin_list_mensajes', function (Blueprint $table) {
				$table->integer('codigo_clasificador')->primary();
				$table->string('descripcion', 255)->nullable();
			});
		}

		// sin_pais_origen
		if (!Schema::hasTable('sin_pais_origen')) {
			Schema::create('sin_pais_origen', function (Blueprint $table) {
				$table->integer('codigo_clasificador')->primary();
				$table->string('descripcion', 50)->nullable();
			});
		}

		// sin_motivo_anulacion_factura
		if (!Schema::hasTable('sin_motivo_anulacion_factura')) {
			Schema::create('sin_motivo_anulacion_factura', function (Blueprint $table) {
				$table->integer('codigo_id')->primary();
				$table->string('descripcion', 100)->nullable();
			});
		}

		// sin_datos_sincronizacion (PK compuesta)
		if (!Schema::hasTable('sin_datos_sincronizacion')) {
			Schema::create('sin_datos_sincronizacion', function (Blueprint $table) {
				$table->string('tipo', 200);
				$table->string('codigo_clasificador', 50);
				$table->text('descripcion')->nullable();
				$table->primary(['tipo', 'codigo_clasificador']);
			});
		}

		// sin_actividades
		if (!Schema::hasTable('sin_actividades')) {
			Schema::create('sin_actividades', function (Blueprint $table) {
				$table->string('codigo_caeb', 25)->primary();
				$table->text('descripcion');
				$table->string('tipo_actividad', 10)->nullable();
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('sin_actividades');
		Schema::dropIfExists('sin_datos_sincronizacion');
		Schema::dropIfExists('sin_motivo_anulacion_factura');
		Schema::dropIfExists('sin_pais_origen');
		Schema::dropIfExists('sin_list_mensajes');
		Schema::dropIfExists('sin_tipo_emision');
		Schema::dropIfExists('sin_tipo_moneda');
	}
};
