<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) Agregar columna anio_cobro si no existe
		if (!Schema::hasColumn('cobro', 'anio_cobro')) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->integer('anio_cobro')->nullable()->after('nro_cobro');
			});
			// Backfill con YEAR(fecha_cobro)
			DB::statement("UPDATE cobro SET anio_cobro = YEAR(fecha_cobro) WHERE anio_cobro IS NULL");
			Schema::table('cobro', function (Blueprint $table) {
				$table->integer('anio_cobro')->nullable(false)->change();
			});
		}

		// 2) Asegurar fila de doc_counter para cada año presente
		$years = DB::table('cobro')->select(DB::raw('DISTINCT anio_cobro as y'))->pluck('y');
		foreach ($years as $y) {
			$scope = 'COBRO:' . (int)$y;
			DB::statement(
				"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 0, NOW(), NOW())\n"
				. "ON DUPLICATE KEY UPDATE updated_at = NOW()",
				[$scope]
			);
		}

		// 3) Reasignar duplicados por (anio_cobro, nro_cobro) conservando el más antiguo
		$dups = DB::table('cobro')
			->select('anio_cobro', 'nro_cobro', DB::raw('COUNT(*) as c'))
			->groupBy('anio_cobro', 'nro_cobro')
			->having('c', '>', 1)
			->get();
		foreach ($dups as $d) {
			$rows = DB::table('cobro')
				->where('anio_cobro', (int)$d->anio_cobro)
				->where('nro_cobro', (int)$d->nro_cobro)
				->orderBy('created_at')
				->get();
			$keepFirst = true;
			foreach ($rows as $r) {
				if ($keepFirst) { $keepFirst = false; continue; }
				$scope = 'COBRO:' . (int)$r->anio_cobro;
				DB::statement(
					"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
					. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
					[$scope]
				);
				$row = DB::selectOne('SELECT LAST_INSERT_ID() AS id');
				$newNro = (int)($row->id ?? 0);
				DB::table('cobro')
					->where('cod_ceta', $r->cod_ceta)
					->where('cod_pensum', $r->cod_pensum)
					->where('tipo_inscripcion', $r->tipo_inscripcion)
					->where('nro_cobro', $r->nro_cobro)
					->where('anio_cobro', $r->anio_cobro)
					->update([ 'nro_cobro' => $newNro ]);
			}
		}

		// 4) Índices no únicos (evitar error por duplicados residuales).
		//    Dejamos la garantía de unicidad al generador atómico por año en el código.
		try { DB::statement('ALTER TABLE cobro DROP INDEX cobro_nro_cobro_unique'); } catch (\Throwable $e) {}
		$hasIdxAnioNro = DB::selectOne("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobro' AND INDEX_NAME = 'idx_cobro_anio_nro' LIMIT 1");
		if (!$hasIdxAnioNro) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->index(['anio_cobro', 'nro_cobro'], 'idx_cobro_anio_nro');
			});
		}
		$hasIdxAnio = DB::selectOne("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobro' AND INDEX_NAME = 'idx_cobro_anio' LIMIT 1");
		if (!$hasIdxAnio) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->index('anio_cobro', 'idx_cobro_anio');
			});
		}
	}

	public function down(): void
	{
		$hasIdxAnioNro = DB::selectOne("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobro' AND INDEX_NAME = 'idx_cobro_anio_nro' LIMIT 1");
		if ($hasIdxAnioNro) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->dropIndex('idx_cobro_anio_nro');
			});
		}
		$hasIdxAnio = DB::selectOne("SELECT 1 AS x FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobro' AND INDEX_NAME = 'idx_cobro_anio' LIMIT 1");
		if ($hasIdxAnio) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->dropIndex('idx_cobro_anio');
			});
		}
		// Nota: dejamos la columna anio_cobro para no perder información.
	}
};
