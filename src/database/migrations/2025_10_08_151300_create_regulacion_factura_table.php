<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('regulacion_factura', function (Blueprint $table) {
			$table->integer('nro_factura');
			$table->integer('anio');
			$table->string('codigo_cafc', 25)->nullable();
			$table->string('codigo_evento_significativo', 10)->nullable();
			$table->text('observacion')->nullable();
			$table->timestamps();
			$table->primary(['nro_factura','anio']);

			$table->index('codigo_cafc', 'idx_regfac_cafc');
			$table->index('codigo_evento_significativo', 'idx_regfac_evento');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('regulacion_factura');
	}
};
