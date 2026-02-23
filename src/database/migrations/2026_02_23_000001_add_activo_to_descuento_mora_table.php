<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddActivoToDescuentoMoraTable extends Migration
{
	public function up()
	{
		if (Schema::hasTable('descuento_mora') && !Schema::hasColumn('descuento_mora', 'activo')) {
			Schema::table('descuento_mora', function (Blueprint $table) {
				$table->boolean('activo')->default(true)->after('observaciones');
				$table->index('activo', 'idx_descuento_mora_activo');
			});
		}
	}

	public function down()
	{
		if (Schema::hasTable('descuento_mora') && Schema::hasColumn('descuento_mora', 'activo')) {
			Schema::table('descuento_mora', function (Blueprint $table) {
				$table->dropIndex('idx_descuento_mora_activo');
				$table->dropColumn('activo');
			});
		}
	}
}
