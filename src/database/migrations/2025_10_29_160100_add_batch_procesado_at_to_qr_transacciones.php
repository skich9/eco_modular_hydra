<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasColumn('qr_transacciones', 'batch_procesado_at')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				$table->timestamp('batch_procesado_at')->nullable()->after('updated_at');
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasColumn('qr_transacciones', 'batch_procesado_at')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				$table->dropColumn('batch_procesado_at');
			});
		}
	}
};
