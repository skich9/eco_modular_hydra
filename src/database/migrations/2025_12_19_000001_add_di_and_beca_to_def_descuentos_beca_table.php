<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


class AddDiAndBecaToDefDescuentosBecaTable extends Migration
{
	public function up()
	{
		Schema::table('def_descuentos_beca', function (Blueprint $table) {
			$table->boolean('d_i')->nullable()->after('estado');
			$table->boolean('beca')->nullable()->after('d_i');
		});
	}

	public function down()
	{
		Schema::table('def_descuentos_beca', function (Blueprint $table) {
			$table->dropColumn(['d_i', 'beca']);
		});
	}
}
