<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('cuentas_bancarias', function (Blueprint $table) {
			$table->increments('id_cuentas_bancarias');
			$table->string('banco', 50);
			$table->string('numero_cuenta', 100);
			$table->string('tipo_cuenta', 100);
			$table->string('titular', 100);
			$table->boolean('habilitado_QR')->nullable();
			$table->boolean('estado')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cuentas_bancarias');
	}
};
