<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		DB::beginTransaction();
		try {
			// Reasignar duplicados existentes (conservar el más antiguo por created_at)
			$dups = DB::table('cobro')
				->select('nro_cobro', DB::raw('COUNT(*) as c'))
				->groupBy('nro_cobro')
				->having('c', '>', 1)
				->pluck('nro_cobro');
			foreach ($dups as $nro) {
				$rows = DB::table('cobro')
					->where('nro_cobro', $nro)
					->orderBy('created_at')
					->get();
				$keepFirst = true;
				foreach ($rows as $r) {
					if ($keepFirst) { $keepFirst = false; continue; }
					$anio = (int) date('Y', strtotime((string)$r->fecha_cobro));
					$scope = 'COBRO:' . $anio;
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
						->where('fecha_cobro', $r->fecha_cobro)
						->update([ 'nro_cobro' => $newNro ]);
				}
			}

			DB::commit();
		} catch (\Throwable $e) {
			DB::rollBack();
			throw $e;
		}
	}

	public function down(): void
	{
		// No se creó índice único en esta migración; no hay nada que revertir.
	}
};
