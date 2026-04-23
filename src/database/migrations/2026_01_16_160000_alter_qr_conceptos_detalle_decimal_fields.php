<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// Actualizar valores NULL a 0 antes de cambiar la estructura
		DB::statement('UPDATE qr_conceptos_detalle SET descuento = 0 WHERE descuento IS NULL');
		DB::statement('UPDATE qr_conceptos_detalle SET multa = 0 WHERE multa IS NULL');

		Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
			$table->decimal('descuento', 10, 2)->default(0)->change();
			$table->decimal('multa', 10, 2)->default(0)->change();
			$table->decimal('monto_saldo', 10, 2)->nullable()->change();
		});
	}

	public function down(): void
	{
		Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
			DB::statement('ALTER TABLE qr_conceptos_detalle MODIFY descuento INT NULL');
			DB::statement('ALTER TABLE qr_conceptos_detalle MODIFY multa INT NULL');
			DB::statement('ALTER TABLE qr_conceptos_detalle MODIFY monto_saldo INT NULL');
		});
	}
};
