<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	public function up(): void
	{
		try {
			DB::statement('ALTER TABLE qr_transacciones ADD COLUMN tipo_identidad_cliente INT NULL AFTER nombre_cliente');
		} catch (\Throwable $e) {
			// Ignorar si la columna ya existe
		}
	}

	public function down(): void
	{
		try {
			DB::statement('ALTER TABLE qr_transacciones DROP COLUMN tipo_identidad_cliente');
		} catch (\Throwable $e) {
			// No-op si no es posible revertir
		}
	}
};
