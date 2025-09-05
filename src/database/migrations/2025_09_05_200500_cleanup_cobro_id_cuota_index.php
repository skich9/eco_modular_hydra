<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

		// Detectar estado actual
		$hasOldIdx = $this->indexExists('cobro', 'pagos_cuota_id_foreign');
		$hasNewIdx = $this->indexExists('cobro', 'idx_cobro_id_cuota');
		$hasFk = $this->foreignKeyExists('cobro', 'fk_cobro_id_cuota');

		// Asegurar índice nuevo consistente
		if (!$hasNewIdx) {
			DB::statement('CREATE INDEX `idx_cobro_id_cuota` ON `cobro` (`id_cuota`)');
		}

		// Si existe el índice "engañoso", limpiarlo de forma segura
		if ($hasOldIdx) {
			if ($hasFk) {
				// Soltar FK, eliminar índice viejo y recrear FK utilizando el índice nuevo
				Schema::table('cobro', function (Blueprint $table) {
					$table->dropForeign('fk_cobro_id_cuota');
				});
				DB::statement('DROP INDEX `pagos_cuota_id_foreign` ON `cobro`');
				if (Schema::hasTable('cuotas')) {
					Schema::table('cobro', function (Blueprint $table) {
						$table->foreign('id_cuota', 'fk_cobro_id_cuota')
							->references('id_cuota')->on('cuotas')
							->onDelete('restrict')->onUpdate('restrict');
					});
				}
			} else {
				// Sin FK: eliminar índice viejo si existe
				DB::statement('DROP INDEX `pagos_cuota_id_foreign` ON `cobro`');
			}
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		if (!Schema::hasTable('cobro')) {
			return;
		}

		$hasNewIdx = $this->indexExists('cobro', 'idx_cobro_id_cuota');
		$hasFk = $this->foreignKeyExists('cobro', 'fk_cobro_id_cuota');

		if ($hasNewIdx) {
			if ($hasFk) {
				Schema::table('cobro', function (Blueprint $table) {
					$table->dropForeign('fk_cobro_id_cuota');
				});
			}
			// Restaurar índice antiguo si no existe
			if (!$this->indexExists('cobro', 'pagos_cuota_id_foreign')) {
				DB::statement('CREATE INDEX `pagos_cuota_id_foreign` ON `cobro` (`id_cuota`)');
			}
			// Eliminar índice nuevo
			DB::statement('DROP INDEX `idx_cobro_id_cuota` ON `cobro`');
			// Recrear FK
			if (Schema::hasTable('cuotas')) {
				Schema::table('cobro', function (Blueprint $table) {
					$table->foreign('id_cuota', 'fk_cobro_id_cuota')
						->references('id_cuota')->on('cuotas')
						->onDelete('restrict')->onUpdate('restrict');
				});
			}
		}
	}

	private function indexExists(string $table, string $indexName): bool
	{
		$db = DB::getDatabaseName();
		$result = DB::selectOne(
			"SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
			[$db, $table, $indexName]
		);
		return $result && (int) ($result->c ?? 0) > 0;
	}

	private function foreignKeyExists(string $table, string $foreignName): bool
	{
		$db = DB::getDatabaseName();
		$result = DB::selectOne(
			"SELECT COUNT(1) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
			[$db, $table, $foreignName]
		);
		return $result && (int) ($result->c ?? 0) > 0;
	}
};
