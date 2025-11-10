<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				if (!Schema::hasColumn('qr_transacciones', 'processed')) {
					$table->boolean('processed')->default(false)->after('updated_at');
				}
				if (!Schema::hasColumn('qr_transacciones', 'processed_at')) {
					$table->timestamp('processed_at')->nullable()->after('processed');
				}
				if (!Schema::hasColumn('qr_transacciones', 'saved_by_user')) {
					$table->boolean('saved_by_user')->default(false)->after('processed_at');
				}
				if (!Schema::hasColumn('qr_transacciones', 'process_error')) {
					$table->text('process_error')->nullable()->after('saved_by_user');
				}
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				foreach (['processed','processed_at','saved_by_user','process_error'] as $col) {
					try { $table->dropColumn($col); } catch (\Throwable $e) {}
				}
			});
		}
	}
};
