<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('factura', function (Blueprint $table) {
			if (!Schema::hasColumn('factura', 'cliente')) {
				$table->string('cliente', 255)->nullable()->after('monto_total');
			}
		});
	}

	public function down(): void
	{
		Schema::table('factura', function (Blueprint $table) {
			if (Schema::hasColumn('factura', 'cliente')) {
				$table->dropColumn('cliente');
			}
		});
	}
};
