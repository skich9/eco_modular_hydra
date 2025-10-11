<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (Schema::hasTable('recibo') && Schema::hasColumn('recibo', 'cod_tipo_doc_identidad')) {
			Schema::table('recibo', function (Blueprint $table) {
				$table->integer('cod_tipo_doc_identidad')->nullable()->change();
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('recibo') && Schema::hasColumn('recibo', 'cod_tipo_doc_identidad')) {
			Schema::table('recibo', function (Blueprint $table) {
				$table->integer('cod_tipo_doc_identidad')->nullable(false)->change();
			});
		}
	}
};
