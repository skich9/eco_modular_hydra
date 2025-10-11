<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (Schema::hasTable('factura') && !Schema::hasColumn('factura', 'codigo_recepcion')) {
			Schema::table('factura', function (Blueprint $table) {
				$table->string('codigo_recepcion', 100)->nullable()->after('cuf');
				$table->index('codigo_recepcion', 'idx_factura_cod_recepcion');
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('factura') && Schema::hasColumn('factura', 'codigo_recepcion')) {
			Schema::table('factura', function (Blueprint $table) {
				$table->dropIndex('idx_factura_cod_recepcion');
				$table->dropColumn('codigo_recepcion');
			});
		}
	}
};
