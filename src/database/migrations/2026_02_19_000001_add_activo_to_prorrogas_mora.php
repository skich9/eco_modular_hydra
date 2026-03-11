<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddActivoToProrrogasMora extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table('prorrogas_mora', function (Blueprint $table) {
			if (!Schema::hasColumn('prorrogas_mora', 'activo')) {
				$table->boolean('activo')->default(true)->after('fecha_fin_prorroga');
			}
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::table('prorrogas_mora', function (Blueprint $table) {
			if (Schema::hasColumn('prorrogas_mora', 'activo')) {
				$table->dropColumn('activo');
			}
		});
	}
}
