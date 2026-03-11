<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontoPagadoToAsignacionMora extends Migration
{
	/**
	 * Run the migrations.
	 *
	 * Agregar campo monto_pagado a la tabla asignacion_mora
	 */
	public function up()
	{
		if (Schema::hasTable('asignacion_mora') && !Schema::hasColumn('asignacion_mora', 'monto_pagado')) {
			Schema::table('asignacion_mora', function (Blueprint $table) {
				$table->decimal('monto_pagado', 10, 2)->default(0)->after('monto_descuento')
					->comment('Monto total pagado de la mora');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down()
	{
		if (Schema::hasTable('asignacion_mora') && Schema::hasColumn('asignacion_mora', 'monto_pagado')) {
			Schema::table('asignacion_mora', function (Blueprint $table) {
				$table->dropColumn('monto_pagado');
			});
		}
	}
}
