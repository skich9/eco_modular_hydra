<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdMoraVinculadaToAsignacionMora extends Migration
{
	public function up()
	{
		Schema::table('asignacion_mora', function (Blueprint $table) {
			$table->unsignedBigInteger('id_mora_vinculada')->nullable()->after('id_asignacion_vinculada');
			$table->foreign('id_mora_vinculada')
				->references('id_asignacion_mora')
				->on('asignacion_mora')
				->onDelete('set null');
		});
	}

	public function down()
	{
		Schema::table('asignacion_mora', function (Blueprint $table) {
			$table->dropForeign(['id_mora_vinculada']);
			$table->dropColumn('id_mora_vinculada');
		});
	}
}
