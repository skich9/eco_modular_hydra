<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClienteAndNroDocumentoToRecibo extends Migration
{
	public function up()
	{
		Schema::table('recibo', function (Blueprint $table) {
			$table->string('cliente', 255)->nullable()->after('id_usuario');
			$table->string('nro_documento_cobro', 50)->nullable()->after('cliente');
		});
	}

	public function down()
	{
		Schema::table('recibo', function (Blueprint $table) {
			$table->dropColumn(['cliente', 'nro_documento_cobro']);
		});
	}
}
