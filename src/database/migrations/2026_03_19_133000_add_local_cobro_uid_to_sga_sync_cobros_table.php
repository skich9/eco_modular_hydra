<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLocalCobroUidToSgaSyncCobrosTable extends Migration
{
	public function up()
	{
		Schema::table('sga_sync_cobros', function (Blueprint $table) {
			if (!Schema::hasColumn('sga_sync_cobros', 'local_cobro_uid')) {
				$table->string('local_cobro_uid', 50)->nullable()->after('local_anio_cobro');
			}
		});
	}

	public function down()
	{
		Schema::table('sga_sync_cobros', function (Blueprint $table) {
			if (Schema::hasColumn('sga_sync_cobros', 'local_cobro_uid')) {
				$table->dropColumn('local_cobro_uid');
			}
		});
	}
}

return new AddLocalCobroUidToSgaSyncCobrosTable();
