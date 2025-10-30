<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	public function up(): void
	{
		DB::statement("ALTER TABLE qr_transacciones MODIFY codigo_qr VARCHAR(50) NULL");
	}

	public function down(): void
	{
		DB::statement("ALTER TABLE qr_transacciones MODIFY codigo_qr INT NOT NULL");
	}
};
