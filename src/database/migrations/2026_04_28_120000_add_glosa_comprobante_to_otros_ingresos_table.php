<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('otros_ingresos')) {
			return;
		}
		if (!Schema::hasColumn('otros_ingresos', 'glosa_comprobante')) {
			Schema::table('otros_ingresos', function (Blueprint $table) {
				$table->text('glosa_comprobante')->nullable()->after('concepto');
			});
		}
	}

	public function down(): void
	{
		if (!Schema::hasTable('otros_ingresos')) {
			return;
		}
		if (Schema::hasColumn('otros_ingresos', 'glosa_comprobante')) {
			Schema::table('otros_ingresos', function (Blueprint $table) {
				$table->dropColumn('glosa_comprobante');
			});
		}
	}
};
