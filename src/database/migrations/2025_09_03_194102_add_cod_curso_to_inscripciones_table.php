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
		Schema::table('inscripciones', function (Blueprint $table) {
			$table->text('cod_curso')->nullable()->after('cod_pensum');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('inscripciones', function (Blueprint $table) {
			$table->dropColumn('cod_curso');
		});
	}
};
