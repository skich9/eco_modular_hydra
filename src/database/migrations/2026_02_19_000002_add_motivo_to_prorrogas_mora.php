<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMotivoToProrrogasMora extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::table('prorrogas_mora', function (Blueprint $table) {
			$table->text('motivo')->nullable()->after('activo');
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
			$table->dropColumn('motivo');
		});
	}
}
