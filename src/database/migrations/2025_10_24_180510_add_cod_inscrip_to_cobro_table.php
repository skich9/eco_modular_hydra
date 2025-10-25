<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			if (!Schema::hasColumn('cobro', 'cod_inscrip')) {
				$table->unsignedBigInteger('cod_inscrip')->nullable()->after('tipo_inscripcion');
				$table->index('cod_inscrip', 'idx_cobro_cod_inscrip');
			}
		});
	}

	public function down(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			if (Schema::hasColumn('cobro', 'cod_inscrip')) {
				$table->dropIndex('idx_cobro_cod_inscrip');
				$table->dropColumn('cod_inscrip');
			}
		});
	}
};
