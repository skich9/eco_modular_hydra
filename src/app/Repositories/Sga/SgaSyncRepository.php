<?php

namespace App\Repositories\Sga;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SgaSyncRepository
{
	/**
	 * Sincroniza estudiantes desde una conexión SGA (sga_elec | sga_mec)
	 *
	 * @param string $source  Nombre de conexión DB (sga_elec|sga_mec)
	 * @param int    $chunk   Tamaño de lote
	 * @param bool   $dryRun  Si true, no escribe en BD; solo cuenta
	 * @return array {source, total, inserted, updated}
	 */
	public function syncEstudiantes(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';

		$total = 0; $inserted = 0; $updated = 0;

		if (!Schema::hasTable('estudiantes')) {
			return compact('source','total','inserted','updated');
		}

		DB::connection($source)
			->table('estudiante')
			->orderBy('cod_ceta')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, $dryRun) {
				$total += count($rows);

				$payload = [];
				$now = now();
				foreach ($rows as $r) {
					$codCeta = (int) ($r->cod_ceta ?? 0);
					if ($codCeta === 0) { continue; }
					$payload[] = [
						'cod_ceta'   => $codCeta,
						'ci'         => '', // SGA no provee CI en tabla estudiante; usamos cadena vacía para cumplir NOT NULL
						'nombres'    => trim((string) ($r->nombres ?? '')),
						'ap_paterno' => trim((string) ($r->ap_paterno ?? '')),
						'ap_materno' => trim((string) ($r->ap_materno ?? '')),
						'email'      => $this->normalizeEmail($r->email ?? null),
						'estado'     => null,
						'created_at' => $now,
						'updated_at' => $now,
					];
				}

				if ($dryRun || empty($payload)) {
					return;
				}

				// Determinar existentes para separar inserts/updates y no sobreescribir CI si hubiera
				$ids = array_column($payload, 'cod_ceta');
				$existing = DB::table('estudiantes')->whereIn('cod_ceta', $ids)->pluck('cod_ceta')->all();
				$existingMap = array_fill_keys($existing, true);

				$toInsert = [];
				$toUpdate = [];
				foreach ($payload as $row) {
					if (isset($existingMap[$row['cod_ceta']])) {
						$toUpdate[] = [
							'cod_ceta'   => $row['cod_ceta'],
							'nombres'    => $row['nombres'],
							'ap_paterno' => $row['ap_paterno'],
							'ap_materno' => $row['ap_materno'],
							'email'      => $row['email'],
							'estado'     => $row['estado'],
							'updated_at' => $row['updated_at'],
						];
						$updated++;
					} else {
						$toInsert[] = $row;
						$inserted++;
					}
				}

				if (!empty($toInsert)) {
					DB::table('estudiantes')->insert($toInsert);
				}
				if (!empty($toUpdate)) {
					foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
						foreach ($chunkRows as $r) {
							DB::table('estudiantes')
								->where('cod_ceta', $r['cod_ceta'])
								->update($r);
						}
					}
				}
			});

		return compact('source','total','inserted','updated');
	}

	/**
	 * Sincroniza inscripciones desde SGA (registro_inscripcion) hacia inscripciones (MySQL)
	 * Usa trazabilidad: carrera + source_cod_inscrip (id origen)
	 */
	public function syncInscripciones(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		if (!Schema::hasTable('inscripciones')) {
			return compact('source','total','inserted','updated');
		}

		$defaultUserId = (int) (env('SYNC_DEFAULT_USER_ID', 1));

		DB::connection($source)
			->table('registro_inscripcion')
			->orderBy('cod_inscrip')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun, $carreraLabel, $defaultUserId) {
				$total += count($rows);

				$now = now();
				$payload = [];

				// 1) Preparar mapeo de pensum (SGA -> local) para esta carrera, prefiriendo coincidencias directas en pensums
				$sgaCodes = [];
				foreach ($rows as $r) {
					$cp = trim((string) ($r->cod_pensum ?? ''));
					if ($cp !== '') { $sgaCodes[] = mb_substr($cp, 0, 50); }
				}
				$sgaCodes = array_values(array_unique($sgaCodes));
				$map = [];
				// a) Códigos ya existentes en pensums (clave y valor iguales)
				if (!empty($sgaCodes)) {
					$existingLocal = DB::table('pensums')->whereIn('cod_pensum', $sgaCodes)->pluck('cod_pensum')->all();
					foreach ($existingLocal as $cp) { $map[$cp] = $cp; }
				}
				if (!empty($sgaCodes)) {
					$pairs = DB::table('pensum_map')
						->where('carrera', $carreraLabel)
						->whereIn('cod_pensum_sga', $sgaCodes)
						->pluck('cod_pensum_local', 'cod_pensum_sga')
						->all();
					$map = array_replace($map, $pairs); // [sga => local], prioridad a iguales
				}

				// 2) Construir payload usando mapeo; omitir si no hay mapeo
				foreach ($rows as $r) {
					$codInsSga = (int) ($r->cod_inscrip ?? 0);
					$codCeta = (int) ($r->cod_ceta ?? 0);
					if ($codInsSga === 0 || $codCeta === 0) { continue; }
					$codPensumSga = $this->substr((string) ($r->cod_pensum ?? ''), 50);
					if ($codPensumSga === '' || !isset($map[$codPensumSga])) {
						$skipped++;
						continue; // no hay mapeo de pensum -> evitar violación de FK
					}
					$codPensumLocal = $map[$codPensumSga];
					$payload[] = [
						'carrera'             => $carreraLabel,
						'source_cod_inscrip'  => $codInsSga,
						'id_usuario'          => $defaultUserId,
						'cod_ceta'            => $codCeta,
						'cod_pensum'          => $this->substr((string) $codPensumLocal, 50),
						'cod_pensum_sga'      => $codPensumSga,
						'cod_curso'           => (string) ($r->cod_curso ?? ''),
						'gestion'             => $this->substr((string) ($r->gestion ?? ''), 20),
						'tipo_estudiante'     => $this->substrNull($r->tipo_estudiante ?? null, 20),
						'fecha_inscripcion'   => $this->toDate($r->fecha_inscripcion ?? null),
						'tipo_inscripcion'    => $this->substr((string) ($r->tipo_inscripcion ?? ''), 30),
						'created_at'          => $now,
						'updated_at'          => $now,
					];
				}

				if ($dryRun || empty($payload)) {
					return;
				}

				// Upsert por (carrera, source_cod_inscrip)
				DB::table('inscripciones')->upsert(
					$payload,
					['carrera','source_cod_inscrip'],
					['id_usuario','cod_ceta','cod_pensum','cod_curso','gestion','tipo_estudiante','fecha_inscripcion','tipo_inscripcion','updated_at']
				);

				// Contabilizar inserts/updates estimados por carrera
				$ids = array_column($payload, 'source_cod_inscrip');
				$existing = DB::table('inscripciones')
					->where('carrera', $carreraLabel)
					->whereIn('source_cod_inscrip', $ids)
					->count();
				$updated += $existing;
				$inserted += (count($payload) - $existing);
			});

		return compact('source','total','inserted','updated','skipped');
	}

	/**
	 * Sincroniza catálogo de documentos (doc_estudiante) desde SGA hacia tabla local 'doc_estudiante'.
	 * La tabla local solo contiene la PK 'nombre_doc'. Se usa insertOrIgnore.
	 */
	public function syncDocEstudiante(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$total = 0; $inserted = 0;

		if (!Schema::hasTable('doc_estudiante')) {
			return compact('source','total','inserted');
		}

		DB::connection($source)
			->table('doc_estudiante')
			->orderBy('nombre_doc')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, $dryRun) {
				$total += count($rows);
				$payload = [];
				foreach ($rows as $r) {
					$nombre = trim((string) ($r->nombre_doc ?? ''));
					if ($nombre === '') { continue; }
					$payload[] = ['nombre_doc' => mb_substr($nombre, 0, 100)];
				}
				if ($dryRun || empty($payload)) { return; }
				$before = (int) DB::table('doc_estudiante')->count();
				DB::table('doc_estudiante')->insertOrIgnore($payload);
				$after = (int) DB::table('doc_estudiante')->count();
				$inserted += max(0, $after - $before);
			});

		return compact('source','total','inserted');
	}

	/**
	 * Sincroniza documentos presentados por estudiante desde SGA hacia tabla local 'doc_presentados'.
	 * Mapeo: cod_documento (SGA) -> id_doc_presentados (local). Upsert por (id_doc_presentados, cod_ceta).
	 */
	public function syncDocPresentados(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		if (!Schema::hasTable('doc_presentados')) {
			return compact('source','total','inserted','updated','skipped');
		}

		DB::connection($source)
			->table('doc_presentados')
			->orderBy('cod_ceta')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun) {
				$total += count($rows);
				if (empty($rows)) { return; }
				$payload = [];
				foreach ($rows as $r) {
					$codCeta = (int) ($r->cod_ceta ?? 0);
					$idDoc = (int) ($r->cod_documento ?? ($r->id_doc_presentados ?? 0));
					if ($codCeta === 0 || $idDoc === 0) { $skipped++; continue; }
					$payload[] = [
						'id_doc_presentados' => $idDoc,
						'cod_ceta'          => $codCeta,
						'numero_doc'        => isset($r->numero_doc) ? mb_substr((string)$r->numero_doc, 0, 150) : null,
						'nombre_doc'        => isset($r->nombre_doc) ? mb_substr((string)$r->nombre_doc, 0, 100) : '',
						'procedencia'       => isset($r->procedencia) ? mb_substr((string)$r->procedencia, 0, 150) : null,
						'entregado'         => isset($r->entregado) ? (bool)$r->entregado : null,
					];
				}
				if ($dryRun || empty($payload)) { return; }

				DB::table('doc_presentados')->upsert(
					$payload,
					['id_doc_presentados','cod_ceta'],
					['numero_doc','nombre_doc','procedencia','entregado']
				);

				// Estimar inserted/updated
				$pairs = array_map(function($p){ return $p['id_doc_presentados'].'-'.$p['cod_ceta']; }, $payload);
				$uniqIds = array_values(array_unique(array_column($payload, 'id_doc_presentados')));
				$uniqCetas = array_values(array_unique(array_column($payload, 'cod_ceta')));
				$existingPairs = [];
				if (!empty($uniqIds) && !empty($uniqCetas)) {
					$existRows = DB::table('doc_presentados')
						->whereIn('id_doc_presentados', $uniqIds)
						->whereIn('cod_ceta', $uniqCetas)
						->select('id_doc_presentados','cod_ceta')
						->get();
					foreach ($existRows as $er) { $existingPairs[$er->id_doc_presentados.'-'.$er->cod_ceta] = true; }
				}
				$existingCount = 0;
				foreach ($pairs as $k) { if (isset($existingPairs[$k])) { $existingCount++; } }
				$updated += $existingCount;
				$inserted += (count($payload) - $existingCount);
			});

		return compact('source','total','inserted','updated','skipped');
	}

	/**
	 * Sincroniza deudas desde SGA hacia la tabla local 'deudas'.
	 * - Origen: public.deudas (SGA)
	 * - Destino: deudas (MySQL) con PK compuesta (cod_ceta, cod_inscrip)
	 * - Requiere que inscripciones estén sincronizadas para resolver cod_inscrip local
	 */
	public function syncDeudas(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		$total = 0; $inserted = 0; $updated = 0; $skippedIns = 0; $skippedEst = 0;

		if (!Schema::hasTable('deudas')) {
			return compact('source','total','inserted','updated');
		}

		DB::connection($source)
			->table('deudas')
			->orderBy('cod_inscrip')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skippedIns, &$skippedEst, $dryRun, $carreraLabel) {
				$total += count($rows);
				if (empty($rows)) { return; }

				$now = now();
				$payload = [];

				// 1) Resolver mapeo de cod_inscrip SGA -> cod_inscrip local por carrera
				$sgaIns = [];
				$codCetas = [];
				foreach ($rows as $r) {
					$sgaIns[] = (int) ($r->cod_inscrip ?? 0);
					$codCetas[] = (int) ($r->cod_ceta ?? 0);
				}
				$sgaIns = array_values(array_unique(array_filter($sgaIns)));
				$codCetas = array_values(array_unique(array_filter($codCetas)));

				$insMap = [];
				if (!empty($sgaIns)) {
					$insRows = DB::table('inscripciones')
						->where('carrera', $carreraLabel)
						->whereIn('source_cod_inscrip', $sgaIns)
						->select('source_cod_inscrip','cod_inscrip')
						->get();
					foreach ($insRows as $ir) { $insMap[(int)$ir->source_cod_inscrip] = (int)$ir->cod_inscrip; }
				}

				// 2) Verificar existencia de estudiantes para los cod_ceta
				$existingCetas = [];
				if (!empty($codCetas)) {
					$existingCetas = DB::table('estudiantes')->whereIn('cod_ceta', $codCetas)->pluck('cod_ceta')->all();
					$existingCetas = array_fill_keys(array_map('intval', $existingCetas), true);
				}

				foreach ($rows as $r) {
					$codCeta = (int) ($r->cod_ceta ?? 0);
					$sgaCodIns = (int) ($r->cod_inscrip ?? 0);
					if ($codCeta === 0 || $sgaCodIns === 0) { continue; }

					if (!isset($insMap[$sgaCodIns])) { $skippedIns++; continue; }
					if (!isset($existingCetas[$codCeta])) { $skippedEst++; continue; }

					$payload[] = [
						'cod_ceta'    => $codCeta,
						'cod_inscrip' => $insMap[$sgaCodIns],
						'ap_paterno'  => (string) ($r->ap_paterno ?? ''),
						'ap_materno'  => (string) ($r->ap_materno ?? ''),
						'nombre'      => (string) ($r->nombres ?? ''),
						'grupo'       => (string) ($r->grupo ?? ''),
						'tipo_ins'    => $r->tipo_ins ?? null,
						'deuda'       => (float) ($r->deuda ?? 0),
						'activo'      => true,
					];
				}

				if ($dryRun || empty($payload)) { return; }

				// 3) Upsert por PK compuesta (cod_ceta, cod_inscrip)
				DB::table('deudas')->upsert(
					$payload,
					['cod_ceta','cod_inscrip'],
					['ap_paterno','ap_materno','nombre','grupo','tipo_ins','deuda','activo']
				);

				// 4) Calcular inserted/updated del lote
				$pairs = array_map(function($p){ return $p['cod_ceta'].'-'.$p['cod_inscrip']; }, $payload);
				$uniqCetas = array_values(array_unique(array_column($payload, 'cod_ceta')));
				$uniqIns = array_values(array_unique(array_column($payload, 'cod_inscrip')));
				$existingPairs = [];
				if (!empty($uniqCetas) && !empty($uniqIns)) {
					$existRows = DB::table('deudas')
						->whereIn('cod_ceta', $uniqCetas)
						->whereIn('cod_inscrip', $uniqIns)
						->select('cod_ceta','cod_inscrip')
						->get();
					foreach ($existRows as $er) { $existingPairs[$er->cod_ceta.'-'.$er->cod_inscrip] = true; }
				}
				$existingCount = 0;
				foreach ($pairs as $k) { if (isset($existingPairs[$k])) { $existingCount++; } }
				$updated += $existingCount;
				$inserted += (count($payload) - $existingCount);
			});

		return [
			'source' => $source,
			'total' => $total,
			'inserted' => $inserted,
			'updated' => $updated,
			'skipped_missing_inscripcion' => $skippedIns,
			'skipped_missing_estudiante' => $skippedEst,
		];
	}

	/**
	 * Sincroniza gestiones desde SGA hacia la tabla local 'gestion'.
	 * Mapeo:
	 *  - gestion (SGA) -> gestion (PK, varchar 30)
	 *  - fecha_inicio -> fecha_ini
	 *  - fecha_fin -> fecha_fin
	 *  - orden -> orden (int)
	 *  - fecha_graduacion -> fecha_graduacion (nullable)
	 *  - activo (bool) -> activo (bool)
	 */
	public function syncGestiones(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$total = 0; $inserted = 0; $updated = 0;

		if (!Schema::hasTable('gestion')) {
			return compact('source','total','inserted','updated');
		}

		DB::connection($source)
			->table('gestion')
			->orderBy('gestion')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, $dryRun) {
				$total += count($rows);
				$now = now();
				$payload = [];
				foreach ($rows as $r) {
					$g = trim((string) ($r->gestion ?? ''));
					if ($g === '') { continue; }
					$payload[] = [
						'gestion'          => mb_substr($g, 0, 30),
						'fecha_ini'        => $this->toDate($r->fecha_inicio ?? null) ?? $this->toDate($r->fecha_ini ?? null),
						'fecha_fin'        => $this->toDate($r->fecha_fin ?? null),
						'orden'            => isset($r->orden) ? (int) $r->orden : 0,
						'fecha_graduacion' => $this->toDate($r->fecha_graduacion ?? null),
						'activo'           => isset($r->activo) ? (bool) $r->activo : null,
						'created_at'       => $now,
						'updated_at'       => $now,
					];
				}

				if ($dryRun || empty($payload)) { return; }

				DB::table('gestion')->upsert(
					$payload,
					['gestion'],
					['fecha_ini','fecha_fin','orden','fecha_graduacion','activo','updated_at']
				);

				$keys = array_column($payload, 'gestion');
				$existing = DB::table('gestion')->whereIn('gestion', $keys)->count();
				$updated += $existing;
				$inserted += (count($payload) - $existing);
			});

		return compact('source','total','inserted','updated');
	}

	/**
	 * Sincroniza pensums desde SGA: toma los distintos cod_pensum de registro_inscripcion
	 * y los inserta/actualiza en la tabla local pensums con la carrera adecuada.
	 * Solo cambia/establece: cod_pensum (PK), codigo_carrera, nombre (=cod_pensum si vacío), activo=true.
	 */
	public function syncPensums(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$carreraNombre = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		// Resolver codigo_carrera por nombre o env
		$codigoCarrera = DB::table('carrera')->where('nombre', $carreraNombre)->value('codigo_carrera');
		if (!$codigoCarrera) {
			$codigoCarrera = $source === 'sga_elec' ? env('CARRERA_CODE_ELEC', null) : env('CARRERA_CODE_MEC', null);
		}

		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		DB::connection($source)
			->table('registro_inscripcion')
			->select('cod_pensum')
			->orderBy('cod_pensum')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun, $codigoCarrera) {
				$now = now();
				$codes = [];
				foreach ($rows as $r) {
					$cp = trim((string) ($r->cod_pensum ?? ''));
					if ($cp !== '') { $codes[] = mb_substr($cp, 0, 50); }
				}
				$codes = array_values(array_unique($codes));
				$total += count($codes);
				if (empty($codes)) return;

				if (!$codigoCarrera) { $skipped += count($codes); return; }

				// Preparar upsert
				$payload = [];
				foreach ($codes as $cp) {
					$payload[] = [
						'cod_pensum' => $cp,
						'codigo_carrera' => $codigoCarrera,
						'nombre' => mb_substr($cp, 0, 40),
						'activo' => true,
						'updated_at' => $now,
						'created_at' => $now,
					];
				}

				if ($dryRun) { return; }

				DB::table('pensums')->upsert(
					$payload,
					['cod_pensum'],
					['codigo_carrera','nombre','activo','updated_at']
				);

				$existing = DB::table('pensums')->whereIn('cod_pensum', $codes)->count();
				$updated += $existing;
				$inserted += (count($codes) - $existing);
			});

		return compact('source','total','inserted','updated','skipped');
	}

	private function substr(string $s, int $len): string { return mb_substr(trim($s), 0, $len); }
	private function substrNull($s, int $len): ?string { $s = trim((string)$s); return $s === '' ? null : mb_substr($s, 0, $len); }
	private function toDate($val): ?string
	{
		if (!$val) return null;
		try {
			return (new \DateTime((string)$val))->format('Y-m-d');
		} catch (\Throwable $e) {
			return null;
		}
	}

	// Helper para construir whereIn sobre tuplas (source, source_cod_inscrip) en MySQL
	private function pairsToWhereIn(array $pairs): array
	{
		// Genera una lista de tuplas como strings "('ELEC',123)"
		return array_map(function($p){
			[$a,$b] = $p;
			$a = addslashes($a);
			return "('{$a}',{$b})";
		}, $pairs);
	}

	private function normalizeEmail($email): ?string
	{
		$email = trim((string) $email);
		if ($email === '') return null;
		// validación simple
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
	}
}
