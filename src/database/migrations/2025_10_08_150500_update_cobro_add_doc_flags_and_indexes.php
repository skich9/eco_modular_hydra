<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			if (!Schema::hasColumn('cobro', 'tipo_documento')) {
				$table->char('tipo_documento', 1)->nullable()->after('id_forma_cobro');
			}
			if (!Schema::hasColumn('cobro', 'medio_doc')) {
				$table->char('medio_doc', 1)->nullable()->after('tipo_documento');
			}
		});

		// Agregar índices solo si no existen
		Schema::table('cobro', function (Blueprint $table) {
			// Laravel 11 no soporta verificación de índices existentes de forma nativa
			// Usamos try-catch para evitar errores si el índice ya existe
			try {
				$table->index('fecha_cobro', 'idx_cobro_fecha_cobro');
			} catch (\Exception $e) {
				// Índice ya existe, continuar
			}

			try {
				$table->index('cod_ceta', 'idx_cobro_cod_ceta');
			} catch (\Exception $e) {
				// Índice ya existe, continuar
			}

			try {
				$table->index('nro_factura', 'idx_cobro_nro_factura');
			} catch (\Exception $e) {
				// Índice ya existe, continuar
			}

			try {
				$table->index('nro_recibo', 'idx_cobro_nro_recibo');
			} catch (\Exception $e) {
				// Índice ya existe, continuar
			}
		});
	}

	public function down(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			// Eliminar índices si existen
			try {
				$table->dropIndex('idx_cobro_fecha_cobro');
			} catch (\Exception $e) {
				// Índice no existe, continuar
			}

			try {
				$table->dropIndex('idx_cobro_cod_ceta');
			} catch (\Exception $e) {
				// Índice no existe, continuar
			}

			try {
				$table->dropIndex('idx_cobro_nro_factura');
			} catch (\Exception $e) {
				// Índice no existe, continuar
			}

			try {
				$table->dropIndex('idx_cobro_nro_recibo');
			} catch (\Exception $e) {
				// Índice no existe, continuar
			}

			if (Schema::hasColumn('cobro', 'tipo_documento')) {
				$table->dropColumn('tipo_documento');
			}
			if (Schema::hasColumn('cobro', 'medio_doc')) {
				$table->dropColumn('medio_doc');
			}
		});
	}
};
