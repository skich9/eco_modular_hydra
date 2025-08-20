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
		if (!Schema::hasTable('costo_semestral')) {
			Schema::create('costo_semestral', function (Blueprint $table) {
			$table->bigInteger('id_costo_semestral')->autoIncrement();
			$table->string('cod_pensum', 50);
			$table->string('gestion', 30);
			$table->bigInteger('cod_inscrip')->nullable();
			$table->string('semestre', 30);
			$table->decimal('monto_semestre', 10, 2);
			$table->unsignedBigInteger('id_usuario');
			$table->timestamps();
			
			$table->primary(['id_costo_semestral', 'cod_pensum', 'gestion']);
			
			$table->foreign('cod_pensum')
				  ->references('cod_pensum')
				  ->on('pensums')
				  ->onDelete('restrict')
				  ->onUpdate('restrict');
				  
			$table->foreign('id_usuario')
				  ->references('id_usuario')
				  ->on('usuarios')
				  ->onDelete('restrict')
				  ->onUpdate('restrict');
			});
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('costo_semestral');
	}
};
