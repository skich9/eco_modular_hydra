<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToSinEventoSignificativo extends Migration {
	public function up()
	{
		Schema::table('sin_evento_significativo', function (Blueprint $table) {
			// Descripción del evento (opcional, para referencia)
			if (!Schema::hasColumn('sin_evento_significativo', 'descripcion_evento')) {
				$table->string('descripcion_evento', 255)->nullable()->after('codigo_evento');
			}
			
			// Observaciones adicionales
			if (!Schema::hasColumn('sin_evento_significativo', 'observaciones')) {
				$table->text('observaciones')->nullable()->after('codigo_punto_venta');
			}
			
			// Timestamps de Laravel
			if (!Schema::hasColumn('sin_evento_significativo', 'created_at')) {
				$table->timestamps();
			}
		});
	}

	public function down()
	{
		Schema::table('sin_evento_significativo', function (Blueprint $table) {
			if (Schema::hasColumn('sin_evento_significativo', 'descripcion_evento')) {
				$table->dropColumn('descripcion_evento');
			}
			if (Schema::hasColumn('sin_evento_significativo', 'observaciones')) {
				$table->dropColumn('observaciones');
			}
			if (Schema::hasColumn('sin_evento_significativo', 'created_at')) {
				$table->dropColumn(['created_at', 'updated_at']);
			}
		});
	}
}
