<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('costo_materia')) {
			return;
		}
		if (!Schema::hasColumn('costo_materia', 'turno')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				$table->string('turno', 150)->nullable()->after('monto_materia');
			});
		}
	}

	public function down(): void
	{
		if (!Schema::hasTable('costo_materia')) { return; }
		if (Schema::hasColumn('costo_materia', 'turno')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				$table->dropColumn('turno');
			});
		}
	}
};
