<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			if (!Schema::hasColumn('cobro', 'tipo_documento')) {
				$table->char('tipo_documento', 1)->nullable()->after('id_forma_cobro');
			}
			if (!Schema::hasColumn('cobro', 'medio_doc')) {
				$table->char('medio_doc', 1)->nullable()->after('tipo_documento');
			}
		});

		// Verificar y agregar índices solo si no existen
		$this->addIndexIfNotExists('cobro', 'fecha_cobro', 'idx_cobro_fecha_cobro');
		$this->addIndexIfNotExists('cobro', 'cod_ceta', 'idx_cobro_cod_ceta');
		$this->addIndexIfNotExists('cobro', 'nro_factura', 'idx_cobro_nro_factura');
		$this->addIndexIfNotExists('cobro', 'nro_recibo', 'idx_cobro_nro_recibo');
	}

	private function addIndexIfNotExists(string $table, string $column, string $indexName): void
	{
		$exists = DB::select(
			"SELECT COUNT(*) as count FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			 AND table_name = ?
			 AND index_name = ?",
			[$table, $indexName]
		);

		if ($exists[0]->count == 0) {
			DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)");
		}
	}

	public function down(): void
	{
		// Eliminar índices si existen
		$this->dropIndexIfExists('cobro', 'idx_cobro_fecha_cobro');
		$this->dropIndexIfExists('cobro', 'idx_cobro_cod_ceta');
		$this->dropIndexIfExists('cobro', 'idx_cobro_nro_factura');
		$this->dropIndexIfExists('cobro', 'idx_cobro_nro_recibo');

		Schema::table('cobro', function (Blueprint $table) {
			if (Schema::hasColumn('cobro', 'tipo_documento')) {
				$table->dropColumn('tipo_documento');
			}
			if (Schema::hasColumn('cobro', 'medio_doc')) {
				$table->dropColumn('medio_doc');
			}
		});
	}

	private function dropIndexIfExists(string $table, string $indexName): void
	{
		$exists = DB::select(
			"SELECT COUNT(*) as count FROM information_schema.statistics
			 WHERE table_schema = DATABASE()
			 AND table_name = ?
			 AND index_name = ?",
			[$table, $indexName]
		);

		if ($exists[0]->count > 0) {
			DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
		}
	}
};
