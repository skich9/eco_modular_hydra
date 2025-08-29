<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::create('formas_cobro', function (Blueprint $table) {
			$table->string('id_forma_cobro', 255)->primary();
			$table->string('nombre', 255);
			$table->text('descripcion')->nullable();
			$table->string('estado', 255)->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('formas_cobro');
	}
};
