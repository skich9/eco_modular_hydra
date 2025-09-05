<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('cobro')) {
			return;
		}

		$db = DB::getDatabaseName();
		// Encontrar TODAS las FKs en cobro(id_cuota) que referencian cuotas(id_cuota)
		$constraints = DB::select(
			"SELECT CONSTRAINT_NAME
			 FROM information_schema.KEY_COLUMN_USAGE
			 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cobro' AND COLUMN_NAME = 'id_cuota'
			   AND REFERENCED_TABLE_NAME = 'cuotas' AND REFERENCED_COLUMN_NAME = 'id_cuota'",
			[$db]
		);

		$keep = 'fk_cobro_id_cuota';
		foreach ($constraints as $row) {
			$name = $row->CONSTRAINT_NAME ?? $row->constraint_name ?? null;
			if (!$name) { continue; }
			if ($name !== $keep) {
				// Eliminar FKs duplicadas dejando solo fk_cobro_id_cuota
				try {
					DB::statement("ALTER TABLE `cobro` DROP FOREIGN KEY `{$name}`");
				} catch (\Throwable $e) {
					// Ignorar si no existe
				}
			}
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		// No se puede restaurar de forma determinística las FKs anteriores
	}
};
