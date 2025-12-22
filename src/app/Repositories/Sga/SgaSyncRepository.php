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
	 * Sincroniza becas y descuentos desde SGA (tabla de_becas) hacia las tablas locales
	 *  - Becas  -> def_descuentos_beca (nombre_beca, descripcion, monto, porcentaje, estado)
	 *  - Descuentos -> def_descuentos (nombre_descuento, descripcion, monto, porcentaje, estado)
	 * Separación por heurística del nombre: contiene la palabra 'BECA' (case-insensitive) => beca; caso contrario => descuento
	 */
	public function syncBecasDescuentos(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = strtolower($source);
		$connections = [];
		switch ($source) {
			case 'sga_elec': $connections = ['sga_elec']; break;
			case 'sga_mec': $connections = ['sga_mec']; break;
			case 'all':
			default: $connections = ['sga_elec','sga_mec']; break;
		}

		$summary = [
			'source' => $source,
			'total' => 0,
			'rows_scanned' => 0,
			'becas_found' => 0,
			'descuentos_found' => 0,
			'becas_inserted' => 0,
			'becas_updated' => 0,
			'descuentos_inserted' => 0,
			'descuentos_updated' => 0,
			'skipped' => 0,
		];

		// Si no existen las tablas destino, no hacemos nada
		if (!\Illuminate\Support\Facades\Schema::hasTable('def_descuentos') || !\Illuminate\Support\Facades\Schema::hasTable('def_descuentos_beca')) {
			return $summary;
		}

		foreach ($connections as $conn) {
			// Resolver nombre real de la tabla fuente en SGA: puede ser de_becas o def_becas (u otros alias)
			$tableCandidates = ['de_becas','def_becas','becas','descuentos_becas'];
			$sourceTable = null;
			foreach ($tableCandidates as $t) {
				try { if (\Illuminate\Support\Facades\Schema::connection($conn)->hasTable($t)) { $sourceTable = $t; break; } }
				catch (\Throwable $e) { /* ignore and continue */ }
			}
			if (!$sourceTable) { continue; }

			\Illuminate\Support\Facades\DB::connection($conn)
				->table($sourceTable)
				->orderBy('cod_beca')
				->chunk($chunk, function ($rows) use (&$summary, $dryRun) {
					$summary['total'] += count($rows);
					$summary['rows_scanned'] += count($rows);
					if (empty($rows)) { return; }

					$becas = [];
					$descuentos = [];
					foreach ($rows as $r) {
						$nombre = trim((string)($r->nombre_beca ?? $r->nom_beca ?? $r->nombre_descuento ?? $r->nombre ?? ''));
						if ($nombre === '') { $summary['skipped']++; continue; }
						$desc = isset($r->descripcion) ? trim((string)$r->descripcion) : (isset($r->detalle) ? trim((string)$r->detalle) : '');
						$monto = isset($r->monto) ? (int)$r->monto : (isset($r->importe) ? (int)$r->importe : 0);
						$pv = $r->porcentaje ?? ($r->es_porcentaje ?? null);
						$porc = $pv !== null ? (bool)($pv === true || $pv === 1 || $pv === '1' || $pv === 't' || $pv === 'T' || $pv === 'true' || $pv === 'TRUE') : false;
						$ev = $r->estado ?? ($r->activo ?? 1);
						$activo = (bool)($ev === true || $ev === 1 || $ev === '1' || $ev === 't' || $ev === 'T' || $ev === 'true' || $ev === 'TRUE');

						$norm = mb_strtoupper($nombre);
						$isBeca = (bool)preg_match('/(^|[^A-ZÑÁÉÍÓÚ])BECA(\b|[^A-ZÑÁÉÍÓÚ])/u', $norm) || (mb_strpos($norm, 'BECA') === 0);

						if ($isBeca) {
							$becas[] = [
								'nombre_beca' => mb_substr($nombre, 0, 255),
								'descripcion' => mb_substr($desc, 0, 65535),
								'monto' => $monto,
								'porcentaje' => $porc,
								'estado' => $activo,
							];
						} else {
							$descuentos[] = [
								'nombre_descuento' => mb_substr($nombre, 0, 255),
								'descripcion' => mb_substr($desc, 0, 65535),
								'monto' => $monto,
								'porcentaje' => $porc,
								'estado' => $activo,
							];
						}
					}

					$summary['becas_found'] += count($becas);
					$summary['descuentos_found'] += count($descuentos);

					if ($dryRun) { return; }

					// 1) Upsert-like por nombre para BECAS usando estrategia insert/update sin requerir índices únicos
					if (!empty($becas)) {
						$names = array_values(array_unique(array_map(function($x){ return (string)$x['nombre_beca']; }, $becas)));
						$existing = \Illuminate\Support\Facades\DB::table('def_descuentos_beca')->whereIn('nombre_beca', $names)->pluck('cod_beca','nombre_beca')->all();
						$toInsert = [];
						$toUpdate = [];
						foreach ($becas as $b) {
							$key = (string)$b['nombre_beca'];
							if (isset($existing[$key])) {
								$toUpdate[] = array_merge($b, ['cod_beca' => (int)$existing[$key]]);
							} else { $toInsert[] = $b; }
						}
						if (!empty($toInsert)) {
							\Illuminate\Support\Facades\DB::table('def_descuentos_beca')->insert($toInsert);
							$summary['becas_inserted'] += count($toInsert);
						}
						if (!empty($toUpdate)) {
							foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
								foreach ($chunkRows as $u) {
									$id = (int)$u['cod_beca']; unset($u['cod_beca']);
									\Illuminate\Support\Facades\DB::table('def_descuentos_beca')->where('cod_beca', $id)->update($u);
									$summary['becas_updated']++;
								}
							}
						}
					}

					// 2) Upsert-like por nombre para DESCUENTOS (tabla def_descuentos)
					if (!empty($descuentos)) {
						$names = array_values(array_unique(array_map(function($x){ return (string)$x['nombre_descuento']; }, $descuentos)));
						$existing = \Illuminate\Support\Facades\DB::table('def_descuentos')->whereIn('nombre_descuento', $names)->pluck('cod_descuento','nombre_descuento')->all();
						$toInsert = [];
						$toUpdate = [];
						foreach ($descuentos as $d) {
							$key = (string)$d['nombre_descuento'];
							if (isset($existing[$key])) {
								$toUpdate[] = array_merge($d, ['cod_descuento' => (int)$existing[$key]]);
							} else { $toInsert[] = $d; }
						}
						if (!empty($toInsert)) {
							\Illuminate\Support\Facades\DB::table('def_descuentos')->insert($toInsert);
							$summary['descuentos_inserted'] += count($toInsert);
						}
						if (!empty($toUpdate)) {
							foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
								foreach ($chunkRows as $u) {
									$id = (int)$u['cod_descuento']; unset($u['cod_descuento']);
									\Illuminate\Support\Facades\DB::table('def_descuentos')->where('cod_descuento', $id)->update($u);
									$summary['descuentos_updated']++;
								}
							}
						}
					}
				});
		}

		return $summary;
	}

	/**
	 * Sincroniza Items de Cobro desde SGA (sin_item_service) hacia la tabla local 'items_cobro'.
	 * - Clave: codigo_producto_interno
	 * - En updates NO se sobreescriben: nro_creditos, costo, created_at
	 * - Usa parámetro económico con nombre 'credito' para setear id_parametro_economico
	 */
	public function syncItemsCobro(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = strtolower($source);
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		if (!Schema::hasTable('items_cobro')) {
			return compact('source','total','inserted','updated','skipped');
		}

		$peId = DB::table('parametros_economicos')
			->whereRaw('LOWER(TRIM(nombre)) = ?', ['credito'])
			->value('id_parametro_economico');
		if (!$peId) {
			return [
				'source' => $source,
				'total' => 0,
				'inserted' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error' => 'No existe parámetro económico con nombre "credito"',
			];
		}

		// Solo sincronizamos desde conexiones SGA
		$allCandidates = ['sga_elec', 'sga_mec'];
		$connections = [];
		switch ($source) {
			case 'sga_elec': $connections = ['sga_elec']; break;
			case 'sga_mec': $connections = ['sga_mec']; break;
			case 'all':
			default: $connections = $allCandidates; break;
		}

		// Filtrar conexiones que realmente tengan la tabla sin_item_service
		$connections = array_values(array_filter($connections, function($conn){
			try { return \Illuminate\Support\Facades\Schema::connection($conn)->hasTable('sin_item_service'); }
			catch (\Throwable $e) { return false; }
		}));

		foreach ($connections as $conn) {
			DB::connection($conn)
				->table('sin_item_service')
				->orderBy('id_item')
				->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun, $peId, $conn) {
					$total += count($rows);
					if (empty($rows)) { return; }

					$now = now();
					$payload = [];
					$seenCodes = [];
					foreach ($rows as $r) {
						$codigo = trim((string) ($r->codigo_producto_interno ?? ''));
						$idItem = isset($r->id_item) ? (int)$r->id_item : 0;
						$imp = isset($r->codigo_producto_impuestos) ? (int)$r->codigo_producto_impuestos : 0;
						// Fallback robusto: generar clave única conservando longitud <= 15
						$prefix = ($conn === 'sga_elec') ? 'E' : (($conn === 'sga_mec') ? 'M' : 'S');
						if ($codigo === '' || $codigo === '0') {
							if ($idItem > 0) {
								// Ej: E1234567890123 (<=15)
								$codigo = $prefix . substr((string)$idItem, -14);
							} elseif ($imp > 0) {
								// Ej: EI99100 (prefijo + I + impuesto)
								$codigo = $prefix . 'I' . substr((string)$imp, -13);
							}
						}
						$codigo = mb_substr($codigo, 0, 15);
						if ($codigo === '' || $codigo === '0') { $skipped++; continue; }
						// Deduplicar dentro del mismo lote para evitar inserciones repetidas de la misma clave
						if (isset($seenCodes[$codigo])) { $skipped++; continue; }
						$seenCodes[$codigo] = true;
						$payload[] = [
							'codigo_producto_interno'  => mb_substr($codigo, 0, 15),
							'nombre_servicio'          => mb_substr((string) ($r->nombre_servicio ?? ''), 0, 100),
							'codigo_producto_impuesto'  => isset($r->codigo_producto_impuestos) ? (int)$r->codigo_producto_impuestos : null,
							'unidad_medida'            => isset($r->unidad_medida) ? (int)$r->unidad_medida : 0,
							'actividad_economica'      => $this->substrNull($r->codigo_actividad_economica ?? null, 255) ?? '',
							'facturado'                 => isset($r->facturado) ? (bool)$r->facturado : false,
							'tipo_item'                 => 'SERVICIO',
							'estado'                    => true,
							'id_parametro_economico'    => (int)$peId,
							'nro_creditos'              => 0,
							'costo'                     => 0,
							'created_at'                => $now,
							'updated_at'                => $now,
						];
					}

					if ($dryRun || empty($payload)) { return; }

					// Detectar existentes por codigo_producto_interno para separar inserts/updates
					$codes = array_column($payload, 'codigo_producto_interno');
					$existing = DB::table('items_cobro')->whereIn('codigo_producto_interno', $codes)->pluck('codigo_producto_interno')->all();
					$existingMap = array_fill_keys(array_map('strval', $existing), true);

					$toInsert = [];
					$toUpdate = [];
					foreach ($payload as $row) {
						$code = (string)$row['codigo_producto_interno'];
						if (isset($existingMap[$code])) {
							$toUpdate[] = [
								'codigo_producto_interno' => $row['codigo_producto_interno'],
								'nombre_servicio'         => $row['nombre_servicio'],
								'codigo_producto_impuesto' => $row['codigo_producto_impuesto'],
								'unidad_medida'           => $row['unidad_medida'],
								'actividad_economica'     => $row['actividad_economica'],
								'facturado'                => $row['facturado'],
								'tipo_item'                => $row['tipo_item'],
								'estado'                   => $row['estado'],
								'id_parametro_economico'   => $row['id_parametro_economico'],
								'updated_at'               => $row['updated_at'],
							];
						} else {
							$toInsert[] = $row;
						}
					}

					if (!empty($toInsert)) {
						DB::table('items_cobro')->insert($toInsert);
						$inserted += count($toInsert);
					}
					if (!empty($toUpdate)) {
						foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
							foreach ($chunkRows as $u) {
								DB::table('items_cobro')
									->where('codigo_producto_interno', $u['codigo_producto_interno'])
									->update($u);
								$updated++;
							}
						}
					}
				});
		}

		return compact('source','total','inserted','updated','skipped');
	}

	/**
	 * Sincroniza materias desde SGA hacia la tabla local 'materia'.
	 * - Origen: materia (SGA) con columnas: sigla_materia, cod_pensum, nombre_materia, nombre_materia_oficial,
	 *   nivel_materia, activa, orden, descripcion, ... (otros campos se ignoran)
	 * - Destino: materia (MySQL) con columnas: sigla_materia, cod_pensum, nombre_materia, nombre_material_oficial,
	 *   nivel_materia, activo, orden, descripcion, nro_creditos
	 * - Requiere mapeo cod_pensum (SGA -> local) por carrera, usando pensum_map cuando el código no existe igual en local.
	 */
	public function syncMaterias(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		if (!Schema::hasTable('materia')) {
			return compact('source','total','inserted','updated','skipped');
		}

		DB::connection($source)
			->table('materia')
			->orderBy('cod_pensum')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun, $carreraLabel) {
				$total += count($rows);
				if (empty($rows)) { return; }

				// 1) Preparar mapeo de pensum (SGA -> local) para esta carrera
				$sgaCodes = [];
				foreach ($rows as $r) {
					$cp = trim((string) ($r->cod_pensum ?? ''));
					if ($cp !== '') { $sgaCodes[] = mb_substr($cp, 0, 50); }
				}
				$sgaCodes = array_values(array_unique($sgaCodes));
				$map = [];
				// a) códigos que ya existen localmente
				if (!empty($sgaCodes)) {
					$existingLocal = DB::table('pensums')->whereIn('cod_pensum', $sgaCodes)->pluck('cod_pensum')->all();
					foreach ($existingLocal as $cp) { $map[$cp] = $cp; }
				}
				// b) mapeo en pensum_map por carrera
				if (!empty($sgaCodes)) {
					$pairs = DB::table('pensum_map')
						->where('carrera', $carreraLabel)
						->whereIn('cod_pensum_sga', $sgaCodes)
						->pluck('cod_pensum_local', 'cod_pensum_sga')
						->all();
					$map = array_replace($map, $pairs); // [sga => local]
				}

				$now = now();
				$payload = [];
				foreach ($rows as $r) {
					$sigla = trim((string) ($r->sigla_materia ?? ''));
					$codSga = trim((string) ($r->cod_pensum ?? ''));
					if ($sigla === '' || $codSga === '') { continue; }
					$codSga = mb_substr($codSga, 0, 50);
					if (!isset($map[$codSga])) { $skipped++; continue; }
					$codLocal = $map[$codSga];
					$level = trim((string) ($r->nivel_materia ?? ''));
					$level = $level === '' ? '1' : mb_substr($level, 0, 50);

					$payload[] = [
						'sigla_materia'           => mb_substr($sigla, 0, 255),
						'cod_pensum'              => mb_substr((string) $codLocal, 0, 50),
						'nombre_materia'          => mb_substr((string) ($r->nombre_materia ?? ''), 0, 50),
						'nombre_material_oficial' => mb_substr((string) ($r->nombre_materia_oficial ?? ''), 0, 50),
						'nivel_materia'           => $level,
						'activo'                  => isset($r->activa) ? (bool) $r->activa : true,
						'orden'                   => isset($r->orden) ? (int) $r->orden : 0,
						'descripcion'             => $this->substrNull($r->descripcion ?? null, 255),
						'nro_creditos'            => isset($r->nro_creditos) ? (float) $r->nro_creditos : 0,
						'created_at'              => $now,
						'updated_at'              => $now,
					];
				}

				if ($dryRun || empty($payload)) { return; }

				// Upsert por PK compuesta (sigla_materia, cod_pensum)
				DB::table('materia')->upsert(
					$payload,
					['sigla_materia','cod_pensum'],
					['nombre_materia','nombre_material_oficial','nivel_materia','activo','orden','descripcion','nro_creditos','updated_at']
				);

				// Estimar inserted/updated
				$pairs = array_map(function($p){ return $p['sigla_materia'].'-'.$p['cod_pensum']; }, $payload);
				$uniqSiglas = array_values(array_unique(array_column($payload, 'sigla_materia')));
				$uniqPensums = array_values(array_unique(array_column($payload, 'cod_pensum')));
				$existingPairs = [];
				if (!empty($uniqSiglas) && !empty($uniqPensums)) {
					$existRows = DB::table('materia')
						->whereIn('sigla_materia', $uniqSiglas)
						->whereIn('cod_pensum', $uniqPensums)
						->select('sigla_materia','cod_pensum')
						->get();
					foreach ($existRows as $er) { $existingPairs[$er->sigla_materia.'-'.$er->cod_pensum] = true; }
				}
				$existingCount = 0;
				foreach ($pairs as $k) { if (isset($existingPairs[$k])) { $existingCount++; } }
				$updated += $existingCount;
				$inserted += (count($payload) - $existingCount);
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
