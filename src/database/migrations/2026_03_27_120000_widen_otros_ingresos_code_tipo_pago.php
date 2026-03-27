<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('otros_ingresos') || !Schema::hasColumn('otros_ingresos', 'code_tipo_pago')) {
			return;
		}
		// Alinear con `formas_cobro.id_forma_cobro` (hasta 255); VARCHAR(10) truncaba y rompía PDF / reportes.
		if (DB::getDriverName() === 'mysql') {
			DB::statement('ALTER TABLE otros_ingresos MODIFY code_tipo_pago VARCHAR(255) NULL');

			return;
		}
		Schema::table('otros_ingresos', function (Blueprint $table) {
			$table->string('code_tipo_pago', 255)->nullable()->change();
		});
	}

	public function down(): void
	{
		if (!Schema::hasTable('otros_ingresos') || !Schema::hasColumn('otros_ingresos', 'code_tipo_pago')) {
			return;
		}
		if (DB::getDriverName() === 'mysql') {
			DB::statement('ALTER TABLE otros_ingresos MODIFY code_tipo_pago VARCHAR(10) NULL');

			return;
		}
		Schema::table('otros_ingresos', function (Blueprint $table) {
			$table->string('code_tipo_pago', 10)->nullable()->change();
		});
	}
};
