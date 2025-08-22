<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		// No-op intencional: No realizar cambios en el esquema de la BD
		// Requisito del proyecto: mantener la PK compuesta existente
		return;
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		// No-op intencional
		return;
	}
};
