<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNroDocumentoCobroToFactura extends Migration
{
	public function up()
	{
		Schema::table('factura', function (Blueprint $table) {
			$table->string('nro_documento_cobro', 50)->nullable()->after('cliente');
		});
	}

	public function down()
	{
		Schema::table('factura', function (Blueprint $table) {
			$table->dropColumn('nro_documento_cobro');
		});
	}
}
