<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 * Elimina el campo 'icono' de la tabla funciones ya que no se utiliza
	 */
	public function up()
	{
		Schema::table('funciones', function (Blueprint $table) {
			if (Schema::hasColumn('funciones', 'icono')) {
				$table->dropColumn('icono');
			}
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down()
	{
		Schema::table('funciones', function (Blueprint $table) {
			$table->string('icono', 50)->nullable()->after('modulo');
		});
	}
};
