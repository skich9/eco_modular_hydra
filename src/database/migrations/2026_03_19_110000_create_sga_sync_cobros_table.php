<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSgaSyncCobrosTable extends Migration
{
	public function up()
	{
		Schema::create('sga_sync_cobros', function (Blueprint $table) {
			$table->id();
			$table->string('source_conn', 20);
			$table->string('source_table', 50);
			$table->string('source_pk', 255);
			$table->unsignedBigInteger('cod_ceta')->nullable();
			$table->string('cod_pensum', 50)->nullable();
			$table->string('gestion', 30)->nullable();
			$table->timestamp('fecha_pago')->nullable();
			$table->decimal('monto', 10, 2)->nullable();
			$table->decimal('descuento', 10, 2)->nullable();
			$table->integer('local_nro_cobro')->nullable();
			$table->integer('local_anio_cobro')->nullable();
			$table->string('status', 20)->default('OK');
			$table->text('error_message')->nullable();
			$table->string('hash_payload', 64)->nullable();
			$table->timestamp('synced_at')->nullable();
			$table->timestamps();

			$table->unique(['source_conn','source_table','source_pk'], 'uk_sga_sync_cobros_source');
			$table->index(['cod_ceta','gestion'], 'idx_sga_sync_cobros_ceta_gestion');
		});
	}

	public function down()
	{
		Schema::dropIfExists('sga_sync_cobros');
	}
}

return new CreateSgaSyncCobrosTable();
