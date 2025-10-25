<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('cuentas_bancarias', function (Blueprint $table) {
			if (!Schema::hasColumn('cuentas_bancarias', 'I_R')) {
				$table->boolean('I_R')->default(false);
			}
		});
	}

	public function down(): void
	{
		Schema::table('cuentas_bancarias', function (Blueprint $table) {
			if (Schema::hasColumn('cuentas_bancarias', 'I_R')) {
				$table->dropColumn('I_R');
			}
		});
	}
};
