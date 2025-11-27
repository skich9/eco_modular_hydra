<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::table('factura_detalle', function (Blueprint $table) {
			$table->integer('codigo_interno')->nullable()->after('codigo_sin');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('factura_detalle', function (Blueprint $table) {
			$table->dropColumn('codigo_interno');
		});
	}
};
