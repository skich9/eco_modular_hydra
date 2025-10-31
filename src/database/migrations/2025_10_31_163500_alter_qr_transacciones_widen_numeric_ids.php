<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				// Ampliar a 64 bits para permitir hasta 13 dígitos sin overflow
				try {
					if (Schema::hasColumn('qr_transacciones', 'nro_transaccion')) {
						$table->unsignedBigInteger('nro_transaccion')->nullable()->change();
					}
				} catch (\Throwable $e) {}
				try {
					if (Schema::hasColumn('qr_transacciones', 'numeroordenoriginante')) {
						$table->unsignedBigInteger('numeroordenoriginante')->nullable()->change();
					}
				} catch (\Throwable $e) {}
				try {
					if (Schema::hasColumn('qr_transacciones', 'documento_cliente')) {
						$table->unsignedBigInteger('documento_cliente')->nullable()->change();
					}
				} catch (\Throwable $e) {}
			});
		}
	}

	public function down(): void
	{
		// No se revierte para evitar pérdida de datos por truncamiento
	}
};
