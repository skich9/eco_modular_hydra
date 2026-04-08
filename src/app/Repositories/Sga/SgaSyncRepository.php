<?php

namespace App\Repositories\Sga;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SgaSyncRepository
{
	/**
	 * Sincroniza usuarios desde SGA (tabla usuario) hacia usuarios (MySQL)
	 *
	 * Match:
	 * - SGA.id_usuario -> usuarios.nickname
	 * - SGA.nombre -> usuarios.nombre
	 * - SGA.ap_paterno -> usuarios.ap_paterno
	 * - SGA.ap_materno -> usuarios.ap_materno
	 * - SGA.ci -> usuarios.ci
	 * - SGA.activo -> usuarios.estado
	 *
	 * Reglas:
	 * - Contraseña inicial: CI (texto) hasheado con Hash::make (compatible con Hash::check)
	 * - id_rol: Secretaria (por nombre) o fallback a 2
	 * - apoyoCobranzas: false
	 */
	public function syncUsuarios(string $source, int $chunk = 1000, bool $dryRun = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		if (!Schema::hasTable('usuarios')) {
			return compact('source','total','inserted','updated','skipped');
		}

		$idRolSecretaria = (int) (DB::table('rol')->where('nombre', 'Secretaria')->value('id_rol') ?? 2);

		// Validar que exista tabla fuente en SGA
		if (!Schema::connection($source)->hasTable('usuario')) {
			return [
				'source' => $source,
				'total' => 0,
				'inserted' => 0,
				'updated' => 0,
				'skipped' => 0,
				'error' => 'No existe la tabla "usuario" en la conexión ' . $source,
			];
		}

		DB::connection($source)
			->table('usuario')
			->orderBy('id_usuario')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, $dryRun, $idRolSecretaria) {
				$total += count($rows);
				if (empty($rows)) { return; }

				$now = now();
				$payload = [];
				foreach ($rows as $r) {
					$nickname = trim((string) ($r->id_usuario ?? ''));
					if ($nickname === '') { $skipped++; continue; }
					$nombre = trim((string) ($r->nombre ?? ''));
					$apP = trim((string) ($r->ap_paterno ?? ''));
					$apM = trim((string) ($r->ap_materno ?? ''));
					$ciSga = trim((string) ($r->ci ?? ''));
					$ci = $ciSga !== '' ? $ciSga : $nickname;
					$passPlain = $ciSga !== '' ? $ci : '0000';
					$activo = (bool) (($r->activo ?? false) === true || ($r->activo ?? 0) === 1 || ($r->activo ?? '') === '1' || ($r->activo ?? '') === 't' || ($r->activo ?? '') === 'T');

					$payload[] = [
						'nickname' => mb_substr($nickname, 0, 40),
						'nombre' => mb_substr($nombre, 0, 30),
						'ap_paterno' => mb_substr($apP, 0, 40),
						'ap_materno' => mb_substr($apM, 0, 40),
						'ci' => mb_substr($ci, 0, 25),
						'contrasenia' => Hash::make($passPlain),
						'estado' => $activo,
						'id_rol' => $idRolSecretaria,
						'apoyoCobranzas' => false,
						'created_at' => $now,
						'updated_at' => $now,
					];
				}

				if ($dryRun || empty($payload)) {
					return;
				}

				$nicknames = array_column($payload, 'nickname');
				$existing = DB::table('usuarios')
					->whereIn('nickname', $nicknames)
					->pluck('nickname')
					->all();
				$existingMap = array_fill_keys(array_map('strval', $existing), true);

				$toInsert = [];
				$toUpdate = [];
				foreach ($payload as $row) {
					$key = (string) $row['nickname'];
					if (isset($existingMap[$key])) {
						$toUpdate[] = [
							'nickname' => $row['nickname'],
							'nombre' => $row['nombre'],
							'ap_paterno' => $row['ap_paterno'],
							'ap_materno' => $row['ap_materno'],
							'ci' => $row['ci'],
							'contrasenia' => $row['contrasenia'],
							'estado' => $row['estado'],
							'id_rol' => $row['id_rol'],
							'apoyoCobranzas' => $row['apoyoCobranzas'],
							'updated_at' => $row['updated_at'],
						];
						$updated++;
					} else {
						$toInsert[] = $row;
						$inserted++;
					}
				}

				if (!empty($toInsert)) {
					DB::table('usuarios')->insert($toInsert);
				}
				if (!empty($toUpdate)) {
					foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
						foreach ($chunkRows as $u) {
							DB::table('usuarios')
								->where('nickname', $u['nickname'])
								->update($u);
						}
					}
				}
			});

		return compact('source','total','inserted','updated','skipped');
	}

	public function syncCobrosPagoPorGestion(string $source, string $gestion, int $chunk = 1000, bool $dryRun = false, ?int $codCetaFilter = null, ?string $codPensumFilter = null, bool $trace = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$gestion = trim((string) $gestion);
		$total = 0; $inserted = 0; $skipped = 0; $errors = 0;
		$skippedSynced = 0; $skippedMissingUser = 0; $skippedMissingInscripcion = 0;

		if ($gestion === '') {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}
		if (!Schema::hasTable('cobro') || !Schema::hasTable('sga_sync_cobros')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}
		if (!Schema::connection($source)->hasTable('pago') || !Schema::connection($source)->hasTable('registro_inscripcion')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}

		$defaultUserId = (int) (env('SYNC_DEFAULT_USER_ID', 1));
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		try {
			Log::info('SGA sync cobros pago: mode', [
				'source' => $source,
				'gestion' => $gestion,
				'dry_run' => $dryRun,
				'trace' => $trace,
				'writes_cobro' => !$dryRun,
				'writes_sga_sync_cobros' => (!$dryRun) || $trace,
				'default_user_id' => $defaultUserId,
			]);
		} catch (\Throwable $e) {}

		$baseQuery = DB::connection($source)
			->table('pago as p')
			->join('registro_inscripcion as ri', 'ri.cod_inscrip', '=', 'p.cod_inscrip')
			->where('ri.gestion', $gestion)
			->when($codCetaFilter !== null, function ($q) use ($codCetaFilter) {
				$q->where('p.cod_ceta', (int) $codCetaFilter);
			})
			->when($codPensumFilter !== null && trim((string)$codPensumFilter) !== '', function ($q) use ($codPensumFilter) {
				$q->where('p.cod_pensum', (string) $codPensumFilter);
			});

		try {
			$cnt = (int) $baseQuery->count();
			Log::info('SGA sync cobros pago: query count', [
				'source' => $source,
				'gestion' => $gestion,
				'count' => $cnt,
				'dry_run' => $dryRun,
				'trace' => $trace,
				'cod_ceta' => $codCetaFilter,
				'cod_pensum' => $codPensumFilter,
			]);
			if ($cnt === 0) {
				try {
					$gestiones = DB::connection($source)
						->table('registro_inscripcion')
						->select('gestion')
						->distinct()
						->orderByDesc('gestion')
						->limit(15)
						->pluck('gestion')
						->all();
					Log::warning('SGA sync cobros pago: no rows for gestion; sample gestiones', [
						'source' => $source,
						'gestion' => $gestion,
						'sample_gestiones' => $gestiones,
					]);
				} catch (\Throwable $e) {
					Log::warning('SGA sync cobros pago: cannot fetch sample gestiones', [
						'source' => $source,
						'gestion' => $gestion,
						'error' => $e->getMessage(),
					]);
				}
			}
		} catch (\Throwable $e) {
			Log::warning('SGA sync cobros pago: count failed', [
				'source' => $source,
				'gestion' => $gestion,
				'error' => $e->getMessage(),
			]);
		}

		$baseQuery
			->orderBy('p.cod_ceta')
			->orderBy('p.cod_inscrip')
			->orderBy('p.kardex_economico')
			->orderBy('p.num_cuota')
			->orderBy('p.num_pago')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$skipped, &$errors, &$skippedSynced, &$skippedMissingUser, &$skippedMissingInscripcion, $dryRun, $trace, $source, $gestion, $defaultUserId, $carreraLabel) {
				$total += count($rows);
				if (empty($rows)) { return; }

				$now = now();
				$skipLogLimit = 30;
				$missingUserSamples = [];
				$emptyUserSamples = [];
				$sourcePks = [];
				$nicknames = [];
				$sgaCodIns = [];
				foreach ($rows as $r) {
					$sourcePk = $this->makePagoSourcePk($r);
					if ($sourcePk !== '') { $sourcePks[] = $sourcePk; }
					$userNick = trim((string) ($r->usuario ?? ''));
					if ($userNick !== '') { $nicknames[] = mb_substr($userNick, 0, 40); }
					$sgaCod = (int) ($r->cod_inscrip ?? 0);
					if ($sgaCod > 0) { $sgaCodIns[] = $sgaCod; }
				}
				$sourcePks = array_values(array_unique(array_filter($sourcePks)));
				$nicknames = array_values(array_unique(array_filter($nicknames)));
				$sgaCodIns = array_values(array_unique(array_filter($sgaCodIns)));

				$already = [];
				if (!empty($sourcePks)) {
					$syncQuery = DB::table('sga_sync_cobros')
						->where('source_conn', $source)
						->where('source_table', 'pago')
						->whereIn('source_pk', $sourcePks);

					if (!$dryRun) {
						$syncQuery->where('status', 'OK');
					}

					$rowsSynced = $syncQuery
						->pluck('source_pk')
						->all();
					foreach ($rowsSynced as $pk) { $already[(string)$pk] = true; }
				}

				$userMap = [];
				if (!empty($nicknames)) {
					$uRows = DB::table('usuarios')->whereIn('nickname', $nicknames)->select('id_usuario','nickname')->get();
					foreach ($uRows as $u) { $userMap[(string)$u->nickname] = (int) $u->id_usuario; }
				}

				$insMap = [];
				if (!empty($sgaCodIns) && Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
					$insRows = DB::table('inscripciones')
						->where('carrera', $carreraLabel)
						->whereIn('source_cod_inscrip', $sgaCodIns)
						->select('source_cod_inscrip','cod_inscrip','cod_pensum')
						->get();
					foreach ($insRows as $ir) { $insMap[(int)$ir->source_cod_inscrip] = ['cod_inscrip' => (int)$ir->cod_inscrip, 'cod_pensum' => (string)$ir->cod_pensum]; }
				}

				try {
					Log::info('SGA sync cobros pago: chunk map stats', [
						'source' => $source,
						'gestion' => $gestion,
						'rows' => count($rows),
						'source_pks' => count($sourcePks),
						'users_found' => count($userMap),
						'inscripciones_found' => count($insMap),
					]);
				} catch (\Throwable $e) {}

				foreach ($rows as $r) {
					try {
						$sourcePk = $this->makePagoSourcePk($r);
						if ($sourcePk === '') { $skipped++; continue; }
						if (isset($already[$sourcePk])) { $skippedSynced++; continue; }

						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						$sgaCodIns = (int) ($r->cod_inscrip ?? 0);
						if ($sgaCodIns === 0 || !isset($insMap[$sgaCodIns])) {
							$skippedMissingInscripcion++;
							if ($skipLogLimit > 0) {
								$skipLogLimit--;
								Log::warning('SGA sync cobros pago: missing inscripcion', [
									'source' => $source,
									'gestion' => $gestion,
									'cod_ceta' => $codCeta,
									'cod_pensum' => $codPensum,
									'cod_inscrip_sga' => $sgaCodIns,
									'kardex_economico' => isset($r->kardex_economico) ? (string)$r->kardex_economico : null,
									'num_cuota' => isset($r->num_cuota) ? (string)$r->num_cuota : null,
									'num_pago' => isset($r->num_pago) ? (string)$r->num_pago : null,
									'source_pk' => $sourcePk,
								]);
							}
							$this->markSyncCobro($source, 'pago', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), ($dryRun ? 'DRY_ERROR' : 'ERROR'), 'No existe mapeo de inscripción local para source_cod_inscrip');
							continue;
						}
						$codInsLocal = (int) $insMap[$sgaCodIns]['cod_inscrip'];

						$tipoIns = strtoupper(trim((string) ($r->kardex_economico ?? '')));
						if ($tipoIns === '') { $tipoIns = 'NORMAL'; }
						$codTipoCobro = ($tipoIns === 'ARRASTRE') ? 'ARRASTRE' : 'MENSUALIDAD';

						$userNick = trim((string) ($r->usuario ?? ''));
						$idUsuario = $defaultUserId;
						if ($userNick !== '' && isset($userMap[$userNick])) {
							$idUsuario = (int) $userMap[$userNick];
						} else {
							$skippedMissingUser++;
							if ($userNick === '') {
								if (count($emptyUserSamples) < 10) {
									$emptyUserSamples[] = [
										'cod_ceta' => $codCeta,
										'cod_pensum' => $codPensum,
										'cod_inscrip_sga' => $sgaCodIns,
										'source_pk' => $sourcePk,
									];
								}
							} else {
								if (count($missingUserSamples) < 20) {
									$missingUserSamples[] = [
										'usuario_sga' => $userNick,
										'cod_ceta' => $codCeta,
										'cod_pensum' => $codPensum,
										'cod_inscrip_sga' => $sgaCodIns,
										'source_pk' => $sourcePk,
									];
								}
							}
						}

						$idFormaCobro = $this->resolveFormaCobro((string) ($r->code_tipo_pago ?? ''));
						$idCuota = $this->resolveCuotaId($gestion, $codPensum, $tipoIns, (int) ($r->num_cuota ?? 0));

						$razon = null;
						try {
							$razon = trim((string) (
								$r->razon
								?? $r->razon_social
								?? $r->cliente
								?? $r->nombre_cliente
								?? $r->nombre
								?? ''
							));
							if ($razon === '') { $razon = null; }
						} catch (\Throwable $e) { $razon = null; }
						$nroDocumentoPago = null;
						try {
							$nroDocumentoPago = trim((string) (
								$r->nro_documento_pago
								?? $r->nro_documento
								?? $r->nit
								?? $r->documento_cliente
								?? $r->numero_documento
								?? ''
							));
							if ($nroDocumentoPago === '') { $nroDocumentoPago = null; }
						} catch (\Throwable $e) { $nroDocumentoPago = null; }

						$fechaPago = isset($r->fecha_pago) ? $r->fecha_pago : null;
						$anioCobro = $fechaPago ? (int) date('Y', strtotime((string) $fechaPago)) : (int) date('Y');
						$fechaCobro = $fechaPago ? date('Y-m-d H:i:s', strtotime((string)$fechaPago)) : date('Y-m-d H:i:s');
						$monto = (float) ($r->monto ?? 0);
						$descuento = (float) ($r->descuento ?? 0);
						$puMens = (float) ($r->pu_mensualidad ?? 0);
						$cobroCompleto = (bool) ($r->pago_completo ?? false);
						$nroRecibo = isset($r->num_comprobante) ? (int) $r->num_comprobante : null;
						$nroFactura = isset($r->num_factura) ? (int) $r->num_factura : null;

						if ($dryRun) {
							$this->markSyncCobro($source, 'pago', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), 'DRY_OK', null);
							$inserted++;
							continue;
						}

						$nroCobro = $this->nextDocCounter('COBRO:' . $anioCobro);
						$isEfectivo = ($idFormaCobro === 'E');
						$isBancario = in_array($idFormaCobro, ['B','C','D','L','O'], true);
						$nrCorrelativo = null;
						$nbCorrelativo = null;
						if ($isEfectivo && Schema::hasTable('nota_reposicion')) {
							$nrCorrelativo = $this->nextDocCounter('NOTA_REPOSICION');
						}
						if ($isBancario && Schema::hasTable('nota_bancaria')) {
							$nbCorrelativo = $this->nextDocCounter('NOTA_BANCARIA');
						}
						DB::transaction(function () use ($source, $gestion, $now, $anioCobro, $nroCobro, $fechaCobro, $codCeta, $codPensum, $tipoIns, $codInsLocal, $idCuota, $monto, $cobroCompleto, $idUsuario, $idFormaCobro, $puMens, $descuento, $nroFactura, $nroRecibo, $codTipoCobro, $r, $sourcePk, $nrCorrelativo, $nbCorrelativo, $isEfectivo, $isBancario, $razon, $nroDocumentoPago, &$inserted) {
							try {
								Log::info('SGA sync cobros pago: insert cobro', [
									'source' => $source,
									'gestion' => $gestion,
									'source_pk' => $sourcePk,
									'nro_cobro' => $nroCobro,
									'anio_cobro' => $anioCobro,
									'local_cobro_uid' => ((string)$anioCobro) . '-' . ((string)$nroCobro),
									'cod_ceta' => $codCeta,
									'cod_pensum' => $codPensum,
									'tipo_inscripcion' => $tipoIns,
								]);
							} catch (\Throwable $e) {}
							$cobroData = [
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'tipo_inscripcion' => $tipoIns,
								'cod_inscrip' => $codInsLocal,
								'id_cuota' => $idCuota,
								'gestion' => $gestion,
								'nro_cobro' => $nroCobro,
								'anio_cobro' => $anioCobro,
								'monto' => $monto,
								'fecha_cobro' => $fechaCobro,
								'cobro_completo' => $cobroCompleto,
								'observaciones' => isset($r->observaciones) ? (string) $r->observaciones : null,
								'concepto' => isset($r->concepto) ? (string) $r->concepto : null,
								'cod_tipo_cobro' => $codTipoCobro,
								'id_usuario' => $idUsuario,
								'id_forma_cobro' => $idFormaCobro,
								'tipo_documento' => null,
								'medio_doc' => null,
								'pu_mensualidad' => $puMens,
								'order' => (int) ($r->num_cuota ?? 0),
								'descuento' => $descuento,
								'id_cuentas_bancarias' => null,
								'nro_factura' => $nroFactura,
								'nro_recibo' => $nroRecibo,
								'id_item' => null,
								'id_asignacion_costo' => null,
								'created_at' => $now,
								'updated_at' => $now,
							];

							if (Schema::hasColumn('cobro', 'qr_alias')) {
								$cobroData['qr_alias'] = null;
							}
							if (Schema::hasColumn('cobro', 'reposicion_factura')) {
								$cobroData['reposicion_factura'] = false;
							}
							if (!Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
								unset($cobroData['id_cuentas_bancarias']);
							}
							DB::table('cobro')->insert($cobroData);

							try {
								if ($nroRecibo && Schema::hasTable('recibo')) {
									$recKeys = [
										'nro_recibo' => (int) $nroRecibo,
										'anio' => (int) $anioCobro,
									];
									$recData = [
										'id_usuario' => (string) $idUsuario,
										'id_forma_cobro' => (string) $idFormaCobro,
										'cod_ceta' => $codCeta ?: null,
										'monto_total' => (float) $monto,
										'estado' => 'VIGENTE',
										'updated_at' => $now,
										'created_at' => $now,
									];
									if (Schema::hasColumn('recibo', 'cliente')) {
										$recData['cliente'] = $razon;
									}
									if (Schema::hasColumn('recibo', 'nro_documento_cobro')) {
										$recData['nro_documento_cobro'] = $nroDocumentoPago;
									}
									if (Schema::hasColumn('recibo', 'periodo_facturado')) {
										$recData['periodo_facturado'] = $gestion;
									}
									if (Schema::hasColumn('recibo', 'cod_tipo_doc_identidad')) {
										$recData['cod_tipo_doc_identidad'] = null;
									}
									DB::table('recibo')->updateOrInsert($recKeys, $recData);
									try {
										Log::info('SGA sync cobros pago: recibo upsert', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'rec_keys' => $recKeys,
											'cliente' => $razon,
											'nro_documento_cobro' => $nroDocumentoPago,
										]);
									} catch (\Throwable $e) {}
								}
							} catch (\Throwable $e) {
								try {
									Log::warning('SGA sync cobros pago: recibo upsert failed', [
										'source' => $source,
										'gestion' => $gestion,
										'source_pk' => $sourcePk,
										'nro_cobro' => $nroCobro,
										'nro_recibo' => $nroRecibo,
										'error' => $e->getMessage(),
									]);
								} catch (\Throwable $e2) {}
							}

							try {
								if ($nroFactura && Schema::hasTable('factura')) {
									$anioFac = (int) $anioCobro;
									$sucursalFac = $source === 'sga_mec' ? 1 : 0;
									$pvFac = '0';
									$factKeys = [
										'nro_factura' => (int) $nroFactura,
										'anio' => $anioFac,
										'codigo_sucursal' => $sucursalFac,
										'codigo_punto_venta' => (string) $pvFac,
									];
									$factData = [
										'tipo' => 'C',
										'fecha_emision' => $fechaCobro,
										'cod_ceta' => $codCeta ?: null,
										'id_usuario' => (int) $idUsuario,
										'id_forma_cobro' => (string) $idFormaCobro,
										'monto_total' => (float) $monto,
										'estado' => 'VIGENTE',
										'updated_at' => $now,
										'created_at' => $now,
									];
									if (Schema::hasColumn('factura', 'codigo_cufd')) {
										$factData['codigo_cufd'] = null;
									}
									if (Schema::hasColumn('factura', 'cuf')) {
										$factData['cuf'] = null;
									}
									if (Schema::hasColumn('factura', 'periodo_facturado')) {
										$factData['periodo_facturado'] = $gestion;
									}
									if (Schema::hasColumn('factura', 'cliente')) {
										$factData['cliente'] = $razon;
									}
									if (Schema::hasColumn('factura', 'nro_documento_cobro')) {
										$factData['nro_documento_cobro'] = $nroDocumentoPago;
									}
									if (!Schema::hasColumn('factura', 'cod_ceta')) {
										unset($factData['cod_ceta']);
									}
									DB::table('factura')->updateOrInsert($factKeys, $factData);
									try {
										Log::info('SGA sync cobros pago: factura upsert', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'fact_keys' => $factKeys,
											'codigo_sucursal' => $sucursalFac,
											'codigo_punto_venta' => (string) $pvFac,
											'monto_total' => (float) $monto,
											'fecha_emision' => (string) $fechaCobro,
										]);
									} catch (\Throwable $e) {}
								}
							} catch (\Throwable $e) {
								try {
									Log::warning('SGA sync cobros pago: factura upsert failed', [
										'source' => $source,
										'gestion' => $gestion,
										'source_pk' => $sourcePk,
										'nro_cobro' => $nroCobro,
										'nro_factura' => $nroFactura,
										'error' => $e->getMessage(),
									]);
								} catch (\Throwable $e2) {}
							}

							try {
								$idAsign = null;
								$numeroCuota = (int) ($r->num_cuota ?? 0);
								$asigSnap = null;
								if (Schema::hasTable('asignacion_costos') && $numeroCuota > 0) {
									$asigSnap = DB::table('asignacion_costos')
										->where('cod_pensum', $codPensum)
										->where('cod_inscrip', (int) $codInsLocal)
										->where('numero_cuota', $numeroCuota)
										->first();
									if ($asigSnap && isset($asigSnap->id_asignacion_costo)) {
										$idAsign = (int) $asigSnap->id_asignacion_costo;
									}
								}
								try {
									Log::info('SGA sync cobros pago: asignacion_costos lookup', [
										'source' => $source,
										'gestion' => $gestion,
										'source_pk' => $sourcePk,
										'nro_cobro' => $nroCobro,
										'cod_pensum' => $codPensum,
										'cod_inscrip' => (int) $codInsLocal,
										'numero_cuota' => $numeroCuota,
										'found' => (bool) $idAsign,
										'id_asignacion_costo' => $idAsign,
										'asig_estado_pago' => $asigSnap ? (string) ($asigSnap->estado_pago ?? '') : null,
										'asig_monto' => $asigSnap ? (float) ($asigSnap->monto ?? 0) : null,
										'asig_monto_pagado' => $asigSnap ? (float) ($asigSnap->monto_pagado ?? 0) : null,
									]);
								} catch (\Throwable $e) {}

								if (!$idAsign && Schema::hasTable('asignacion_costos')) {
									try {
										$sample = DB::table('asignacion_costos')
											->where('cod_pensum', $codPensum)
											->where('cod_inscrip', (int) $codInsLocal)
											->orderBy('numero_cuota')
											->limit(10)
											->get(['id_asignacion_costo','numero_cuota','estado_pago','monto','monto_pagado']);
										Log::warning('SGA sync cobros pago: asignacion_costos NOT FOUND for cobro', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'cod_pensum' => $codPensum,
											'cod_inscrip' => (int) $codInsLocal,
											'numero_cuota_pago' => (int) ($r->num_cuota ?? 0),
											'sample_asignaciones' => $sample ? $sample->map(function($x){
												return [
													'id' => (int) ($x->id_asignacion_costo ?? 0),
													'numero_cuota' => (int) ($x->numero_cuota ?? 0),
													'estado_pago' => (string) ($x->estado_pago ?? ''),
													'monto' => (float) ($x->monto ?? 0),
													'monto_pagado' => (float) ($x->monto_pagado ?? 0),
												];
											})->toArray() : null,
										]);
									} catch (\Throwable $e) {}
								}

								if ($idAsign && Schema::hasTable('asignacion_costos')) {
									$prevPagado = (float) ($asigSnap->monto_pagado ?? 0);
									$nominal = (float) ($asigSnap->monto ?? 0);
									$descN = 0.0;
									try {
										if (Schema::hasTable('descuento_detalle')) {
											$idDet = (int) ($asigSnap->id_descuentoDetalle ?? 0);
											if ($idDet > 0) {
												$dr = DB::table('descuento_detalle')->where('id_descuento_detalle', $idDet)->first(['monto_descuento']);
												$descN = $dr ? (float) ($dr->monto_descuento ?? 0) : 0.0;
											} else {
												$dr = DB::table('descuento_detalle')->where('id_cuota', (int) $idAsign)->first(['monto_descuento']);
												$descN = $dr ? (float) ($dr->monto_descuento ?? 0) : 0.0;
											}
										}
									} catch (\Throwable $e) { $descN = 0.0; }
									$neto = max(0, $nominal - $descN);
									$montoAplicar = (float) $monto;
									$newPagado = $prevPagado + $montoAplicar;
									$fullNow = ((bool) $cobroCompleto) || ($neto <= 0.0001) || ($newPagado >= ($neto - 0.0001));
									$upd = [
										'monto_pagado' => $newPagado,
										'updated_at' => $now,
									];
									if (Schema::hasColumn('asignacion_costos', 'estado_pago')) {
										$upd['estado_pago'] = $fullNow ? 'COBRADO' : 'PARCIAL';
									}
									if ($fullNow && Schema::hasColumn('asignacion_costos', 'fecha_pago')) {
										$upd['fecha_pago'] = substr((string) $fechaCobro, 0, 10);
									}
									$aff = DB::table('asignacion_costos')->where('id_asignacion_costo', (int) $idAsign)->update($upd);
									try {
										Log::info('SGA sync cobros pago: asignacion_costos updated', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'id_asignacion_costo' => (int) $idAsign,
											'prev_pagado' => $prevPagado,
											'add_monto' => $montoAplicar,
											'new_pagado' => $newPagado,
											'neto' => $neto,
											'full_now' => $fullNow,
											'affected' => $aff,
										]);
									} catch (\Throwable $e) {}

									if (Schema::hasColumn('cobro', 'id_asignacion_costo')) {
										DB::table('cobro')->where('nro_cobro', (int) $nroCobro)->update(['id_asignacion_costo' => (int) $idAsign]);
									}
								}
							} catch (\Throwable $e) {
								try {
									Log::warning('SGA sync cobros pago: asignacion_costos update failed', [
										'source' => $source,
										'gestion' => $gestion,
										'source_pk' => $sourcePk,
										'nro_cobro' => $nroCobro,
										'error' => $e->getMessage(),
									]);
								} catch (\Throwable $e2) {}
							}

							$detalleKeys = ['nro_cobro' => $nroCobro];
							$detalleData = [
								'cod_inscrip' => $codInsLocal,
								'pu_mensualidad' => $puMens,
								'turno' => '',
								'created_at' => $now,
								'updated_at' => $now,
							];
							if (Schema::hasColumn('cobros_detalle_regular', 'cod_pensum')) {
								$detalleData['cod_pensum'] = $codPensum;
							}
							if (Schema::hasColumn('cobros_detalle_regular', 'cod_ceta')) {
								$detalleData['cod_ceta'] = $codCeta;
							}
							if (Schema::hasColumn('cobros_detalle_regular', 'tipo_inscripcion')) {
								$detalleData['tipo_inscripcion'] = $tipoIns;
							}
							if (Schema::hasColumn('cobros_detalle_regular', 'gestion')) {
								$detalleData['gestion'] = $gestion;
							}
							DB::table('cobros_detalle_regular')->updateOrInsert(
								$detalleKeys,
								$detalleData
							);

							try {
								$usuarioNick = trim((string) ($r->usuario ?? ''));
								if ($usuarioNick === '' && Schema::hasTable('usuarios')) {
									$usuarioNick = (string) (DB::table('usuarios')->where('id_usuario', (int) $idUsuario)->value('nickname') ?? '');
								}
								$detalle = isset($r->concepto) ? trim((string) $r->concepto) : '';
								if ($detalle === '') { $detalle = 'Cobro'; }
								$obsOriginal = isset($r->observaciones) ? (string) $r->observaciones : null;
								$fechaNota = substr((string) $fechaCobro, 0, 10);
								$anioFull = $anioCobro;
								$anio2 = (int) date('y', strtotime((string) $fechaCobro));
								$prefijoCarrera = 'E';

								if ($isEfectivo && $nrCorrelativo && Schema::hasTable('nota_reposicion')) {
									$nrData = [
										'correlativo' => (int) $nrCorrelativo,
										'usuario' => $usuarioNick,
										'cod_ceta' => $codCeta,
										'monto' => $monto,
										'concepto_adm' => $detalle,
										'fecha_nota' => $fechaNota,
										'prefijo_carrera' => $prefijoCarrera,
										'anio_reposicion' => $anio2,
										'nro_recibo' => $nroRecibo ? (string) $nroRecibo : null,
										'tipo_ingreso' => null,
									];
									if (Schema::hasColumn('nota_reposicion', 'concepto_est')) {
										$nrData['concepto_est'] = $detalle;
									}
									if (Schema::hasColumn('nota_reposicion', 'observaciones')) {
										$nrData['observaciones'] = $obsOriginal;
									}
									if (Schema::hasColumn('nota_reposicion', 'anulado')) {
										$nrData['anulado'] = false;
									}
									if (Schema::hasColumn('nota_reposicion', 'cont')) {
										$nrData['cont'] = 2;
									}
									DB::table('nota_reposicion')->insert($nrData);
								}

								if ($isBancario && $nbCorrelativo && Schema::hasTable('nota_bancaria')) {
									$fechaDeposito = '';
									try {
										$fechaDeposito = (string) ($r->fecha_deposito ?? $r->fecha_pago ?? '');
										$fechaDeposito = trim($fechaDeposito);
										if ($fechaDeposito !== '') {
											$fechaDeposito = substr((string) date('Y-m-d', strtotime($fechaDeposito)), 0, 10);
										}
									} catch (\Throwable $e) { $fechaDeposito = ''; }
									$nroDeposito = '';
									try {
										$nroDeposito = (string) ($r->nro_deposito ?? $r->nro_transaccion ?? $r->num_deposito ?? $r->deposito ?? '');
										$nroDeposito = trim($nroDeposito);
									} catch (\Throwable $e) { $nroDeposito = ''; }
									$nroCuenta = '';
									try {
										$nroCuenta = (string) ($r->nro_cuenta ?? $r->numero_cuenta ?? $r->cuenta ?? '');
										$nroCuenta = trim($nroCuenta);
									} catch (\Throwable $e) { $nroCuenta = ''; }
									$idCuenta = null;
									$bancoDest = '';
									try {
										if ($nroCuenta !== '' && Schema::hasTable('cuentas_bancarias')) {
											$needle = preg_replace('/\s+/', '', $nroCuenta);
											$cb = DB::table('cuentas_bancarias')
												->whereRaw("REPLACE(REPLACE(REPLACE(TRIM(numero_cuenta), ' ', ''), '-', ''), '.', '') = ?", [
													preg_replace('/\s+/', '', str_replace(['-','.',' '], '', $needle)),
											])
												->first();
											if ($cb) {
												$idCuenta = (int) ($cb->id_cuentas_bancarias ?? 0) ?: null;
												$bancoDest = trim((string) ($cb->banco ?? '')) . ' - ' . trim((string) ($cb->numero_cuenta ?? ''));
											}
										}
									} catch (\Throwable $e) {}
									try {
										Log::info('SGA sync cobros pago: nota_bancaria lookup cuenta', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'forma' => (string) $idFormaCobro,
											'nro_cuenta_src' => $nroCuenta,
											'id_cuentas_bancarias' => $idCuenta,
											'banco_dest' => $bancoDest,
											'fecha_deposito' => $fechaDeposito,
											'nro_deposito' => $nroDeposito,
										]);
									} catch (\Throwable $e) {}

									if ($idCuenta && Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
										DB::table('cobro')->where('nro_cobro', (int) $nroCobro)->update(['id_cuentas_bancarias' => (int) $idCuenta]);
									}

									$nbData = [
										'correlativo' => (int) $nbCorrelativo,
										'usuario' => $usuarioNick,
										'cod_ceta' => $codCeta,
										'monto' => $monto,
										'nro_factura' => $nroFactura ? (string) $nroFactura : '',
										'nro_recibo' => $nroRecibo ? (string) $nroRecibo : '',
										'banco' => $bancoDest,
										'fecha_deposito' => $fechaDeposito,
										'tipo_nota' => (string) $idFormaCobro,
									];
									try {
										Log::info('SGA sync cobros pago: nota_bancaria insert payload', [
											'source' => $source,
											'gestion' => $gestion,
											'source_pk' => $sourcePk,
											'nro_cobro' => $nroCobro,
											'payload' => $nbData,
										]);
									} catch (\Throwable $e) {}
									if (Schema::hasColumn('nota_bancaria', 'anio_deposito')) {
										$nbData['anio_deposito'] = $anioFull;
									}
									if (Schema::hasColumn('nota_bancaria', 'fecha_nota')) {
										$nbData['fecha_nota'] = $fechaNota;
									}
									if (Schema::hasColumn('nota_bancaria', 'concepto')) {
										$nbData['concepto'] = $detalle;
									}
									if (Schema::hasColumn('nota_bancaria', 'nro_transaccion')) {
										$nbData['nro_transaccion'] = $nroDeposito;
									}
									if (Schema::hasColumn('nota_bancaria', 'prefijo_carrera')) {
										$nbData['prefijo_carrera'] = $prefijoCarrera;
									}
									if (Schema::hasColumn('nota_bancaria', 'concepto_est')) {
										$nbData['concepto_est'] = $detalle;
									}
									if (Schema::hasColumn('nota_bancaria', 'observacion')) {
										$nbData['observacion'] = $obsOriginal;
									}
									if (Schema::hasColumn('nota_bancaria', 'anulado')) {
										$nbData['anulado'] = false;
									}
									if (Schema::hasColumn('nota_bancaria', 'banco_origen')) {
										$nbData['banco_origen'] = '';
									}
									if (Schema::hasColumn('nota_bancaria', 'nro_tarjeta')) {
										$nbData['nro_tarjeta'] = null;
									}
									DB::table('nota_bancaria')->insert($nbData);
								}
							} catch (\Throwable $e) {
								try {
									Log::warning('SGA sync cobros pago: nota insert failed', [
										'source' => $source,
										'gestion' => $gestion,
										'source_pk' => $sourcePk,
										'nro_cobro' => $nroCobro,
										'error' => $e->getMessage(),
									]);
								} catch (\Throwable $e2) {}
							}

							$this->markSyncCobro($source, 'pago', $sourcePk, $codCeta, $codPensum, $gestion, $r, $nroCobro, $anioCobro, false, 'OK', null);
							$inserted++;
						});
					} catch (\Throwable $e) {
						$errors++;
						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						$sourcePk = $this->makePagoSourcePk($r);
						try {
							Log::error('SGA sync cobros pago: insert failed', [
								'source' => $source,
								'gestion' => $gestion,
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'source_pk' => $sourcePk,
								'error' => $e->getMessage(),
							]);
						} catch (\Throwable $e2) {}
						if ($sourcePk !== '') {
							$this->markSyncCobro($source, 'pago', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), ($dryRun ? 'DRY_ERROR' : 'ERROR'), $e->getMessage());
						}
					}
				}
			});

		return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
	}

	public function syncCobrosPagoMultaPorGestion(string $source, string $gestion, int $chunk = 1000, bool $dryRun = false, ?int $codCetaFilter = null, ?string $codPensumFilter = null, bool $trace = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$gestion = trim((string) $gestion);
		$total = 0; $inserted = 0; $skipped = 0; $errors = 0;
		$skippedSynced = 0; $skippedMissingUser = 0; $skippedMissingInscripcion = 0;
		$skippedMissingMora = 0;

		if ($gestion === '') {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion','skippedMissingMora');
		}
		if (!Schema::hasTable('cobro') || !Schema::hasTable('sga_sync_cobros')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion','skippedMissingMora');
		}
		if (!Schema::connection($source)->hasTable('pago_multa')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion','skippedMissingMora');
		}

		$defaultUserId = (int) (env('SYNC_DEFAULT_USER_ID', 1));
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		try {
			Log::info('SGA sync cobros multa: mode', [
				'source' => $source,
				'gestion' => $gestion,
				'dry_run' => $dryRun,
				'trace' => $trace,
				'writes_cobro' => !$dryRun,
				'writes_sga_sync_cobros' => (!$dryRun) || $trace,
				'default_user_id' => $defaultUserId,
			]);
		} catch (\Throwable $e) {}

		$codTipoCobroMora = $this->resolveCodTipoCobro('NIVELACION');

		$baseQuery = DB::connection($source)
			->table('pago_multa as pm')
			->where('pm.gestion', $gestion)
			->when($codCetaFilter !== null, function ($q) use ($codCetaFilter) {
				$q->where('pm.cod_ceta', (int) $codCetaFilter);
			})
			->when($codPensumFilter !== null && trim((string)$codPensumFilter) !== '', function ($q) use ($codPensumFilter) {
				$q->where('pm.cod_pensum', (string) $codPensumFilter);
			});

		try {
			$cnt = (int) $baseQuery->count();
			Log::info('SGA sync cobros multa: query count', [
				'source' => $source,
				'gestion' => $gestion,
				'count' => $cnt,
				'dry_run' => $dryRun,
				'trace' => $trace,
				'cod_ceta' => $codCetaFilter,
				'cod_pensum' => $codPensumFilter,
			]);
		} catch (\Throwable $e) {}

		$baseQuery
			->orderBy('pm.cod_ceta')
			->orderBy('pm.cod_pensum')
			->orderBy('pm.kardex_economico')
			->orderBy('pm.num_cuota')
			->orderBy('pm.num_pago')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$skipped, &$errors, &$skippedSynced, &$skippedMissingUser, &$skippedMissingInscripcion, &$skippedMissingMora, $dryRun, $trace, $source, $gestion, $defaultUserId, $carreraLabel, $codTipoCobroMora) {
				$total += count($rows);
				if (empty($rows)) { return; }

				$now = now();
				$sourcePks = [];
				$nicknames = [];
				foreach ($rows as $r) {
					$sourcePk = $this->makePagoMultaSourcePk($r);
					if ($sourcePk !== '') { $sourcePks[] = $sourcePk; }
					$userNick = trim((string) ($r->usuario ?? ''));
					if ($userNick !== '') { $nicknames[] = mb_substr($userNick, 0, 40); }
				}
				$sourcePks = array_values(array_unique(array_filter($sourcePks)));
				$nicknames = array_values(array_unique(array_filter($nicknames)));

				$already = [];
				if (!empty($sourcePks)) {
					$syncQuery = DB::table('sga_sync_cobros')
						->where('source_conn', $source)
						->where('source_table', 'pago_multa')
						->whereIn('source_pk', $sourcePks);
					if (!$dryRun) {
						$syncQuery->where('status', 'OK');
					}
					$rowsSynced = $syncQuery->pluck('source_pk')->all();
					foreach ($rowsSynced as $pk) { $already[(string)$pk] = true; }
				}

				$userMap = [];
				if (!empty($nicknames)) {
					$uRows = DB::table('usuarios')->whereIn('nickname', $nicknames)->select('id_usuario','nickname')->get();
					foreach ($uRows as $u) { $userMap[(string)$u->nickname] = (int) $u->id_usuario; }
				}

				foreach ($rows as $r) {
					try {
						$sourcePk = $this->makePagoMultaSourcePk($r);
						if ($sourcePk === '') { $skipped++; continue; }
						if (isset($already[$sourcePk])) { $skippedSynced++; continue; }

						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						if ($codCeta <= 0 || $codPensum === '') { $skipped++; continue; }

						$tipoIns = strtoupper(trim((string) ($r->kardex_economico ?? '')));
						if ($tipoIns === '') { $tipoIns = 'NORMAL'; }

						$userNick = trim((string) ($r->usuario ?? ''));
						$idUsuario = $defaultUserId;
						if ($userNick !== '' && isset($userMap[$userNick])) {
							$idUsuario = (int) $userMap[$userNick];
						} else {
							$skippedMissingUser++;
						}

						$idFormaCobro = $this->resolveFormaCobro((string) ($r->code_tipo_pago ?? ''));
						$numeroCuota = (int) ($r->num_cuota ?? 0);
						$puMulta = (float) ($r->pu_multa ?? 0);
						$diasMulta = (int) ($r->dias_multa ?? 0);
						$monto = (float) ($r->monto ?? 0);
						$descuento = (float) ($r->descuento ?? 0);
						$cobroCompleto = (bool) ($r->pago_completo ?? false);
						$nroRecibo = isset($r->num_comprobante) ? (int) $r->num_comprobante : null;
						$nroFactura = isset($r->num_factura) ? (int) $r->num_factura : null;

						$razon = null;
						try {
							$razon = trim((string) ($r->razon ?? $r->razon_social ?? $r->cliente ?? $r->nombre_cliente ?? $r->nombre ?? ''));
							if ($razon === '') { $razon = null; }
						} catch (\Throwable $e) { $razon = null; }
						$nroDocumentoPago = null;
						try {
							$nroDocumentoPago = trim((string) ($r->nro_documento_pago ?? $r->nro_documento ?? $r->nit ?? $r->documento_cliente ?? $r->numero_documento ?? ''));
							if ($nroDocumentoPago === '') { $nroDocumentoPago = null; }
						} catch (\Throwable $e) { $nroDocumentoPago = null; }

						$fechaPago = isset($r->fecha_pago) ? $r->fecha_pago : null;
						$anioCobro = $fechaPago ? (int) date('Y', strtotime((string) $fechaPago)) : (int) date('Y');
						$fechaCobro = $fechaPago ? date('Y-m-d H:i:s', strtotime((string)$fechaPago)) : date('Y-m-d H:i:s');

						if ($dryRun) {
							$this->markSyncCobro($source, 'pago_multa', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), 'DRY_OK', null);
							$inserted++;
							continue;
						}

						$insQ = DB::table('inscripciones')
							->where('carrera', $carreraLabel)
							->where('cod_ceta', (int) $codCeta)
							->where('cod_pensum', (string) $codPensum)
							->where('gestion', (string) $gestion);
						if (Schema::hasColumn('inscripciones', 'tipo_inscripcion')) {
							$insQ->where('tipo_inscripcion', $tipoIns);
						}
						$insRow = $insQ->orderByDesc('cod_inscrip')->first(['cod_inscrip','cod_curso']);
						if (!$insRow || !isset($insRow->cod_inscrip)) {
							$skippedMissingInscripcion++;
							$this->markSyncCobro($source, 'pago_multa', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), 'ERROR', 'No existe inscripcion local para cod_ceta/cod_pensum/gestion/tipo_inscripcion');
							continue;
						}
						$codInsLocal = (int) $insRow->cod_inscrip;
						$codCursoLocal = isset($insRow->cod_curso) ? trim((string) $insRow->cod_curso) : '';

						$nroCobro = $this->nextDocCounter('COBRO:' . $anioCobro);
						$isEfectivo = ($idFormaCobro === 'E');
						$isBancario = in_array($idFormaCobro, ['B','C','D','L','O'], true);
						$nrCorrelativo = null;
						$nbCorrelativo = null;
						if ($isEfectivo && Schema::hasTable('nota_reposicion')) {
							$nrCorrelativo = $this->nextDocCounter('NOTA_REPOSICION');
						}
						if ($isBancario && Schema::hasTable('nota_bancaria')) {
							$nbCorrelativo = $this->nextDocCounter('NOTA_BANCARIA');
						}

						DB::transaction(function () use ($source, $gestion, $now, $anioCobro, $nroCobro, $fechaCobro, $codCeta, $codPensum, $tipoIns, $codInsLocal, $codCursoLocal, $monto, $cobroCompleto, $idUsuario, $idFormaCobro, $puMulta, $diasMulta, $descuento, $nroFactura, $nroRecibo, $r, $sourcePk, $nrCorrelativo, $nbCorrelativo, $isEfectivo, $isBancario, $razon, $nroDocumentoPago, $numeroCuota, $codTipoCobroMora, $trace, &$inserted, &$skippedMissingMora) {
							$cobroData = [
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'tipo_inscripcion' => $tipoIns,
								'cod_inscrip' => $codInsLocal,
								'id_cuota' => null,
								'gestion' => $gestion,
								'nro_cobro' => $nroCobro,
								'anio_cobro' => $anioCobro,
								'monto' => $monto,
								'fecha_cobro' => $fechaCobro,
								'cobro_completo' => $cobroCompleto,
								'observaciones' => isset($r->observaciones) ? (string) $r->observaciones : null,
								'concepto' => isset($r->concepto) ? (string) $r->concepto : null,
								'cod_tipo_cobro' => $codTipoCobroMora,
								'id_usuario' => $idUsuario,
								'id_forma_cobro' => $idFormaCobro,
								'tipo_documento' => null,
								'medio_doc' => null,
								'pu_mensualidad' => 0,
								'order' => $numeroCuota,
								'descuento' => $descuento,
								'id_cuentas_bancarias' => null,
								'nro_factura' => $nroFactura,
								'nro_recibo' => $nroRecibo,
								'id_item' => null,
								'id_asignacion_costo' => null,
								'created_at' => $now,
								'updated_at' => $now,
							];
							if (Schema::hasColumn('cobro', 'qr_alias')) {
								$cobroData['qr_alias'] = null;
							}
							if (Schema::hasColumn('cobro', 'reposicion_factura')) {
								$cobroData['reposicion_factura'] = false;
							}
							if (!Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
								unset($cobroData['id_cuentas_bancarias']);
							}
							DB::table('cobro')->insert($cobroData);

							if (Schema::hasTable('cobros_detalle_multa')) {
								DB::table('cobros_detalle_multa')->updateOrInsert([
									'nro_cobro' => $nroCobro,
								], [
									'pu_multa' => (float) $puMulta,
									'dias_multa' => (int) $diasMulta,
									'updated_at' => $now,
									'created_at' => $now,
								]);
							}

							try {
								$idAsign = null;
								$asigSnap = null;
								if (Schema::hasTable('asignacion_costos') && $numeroCuota > 0) {
									$asigSnap = DB::table('asignacion_costos')
										->where('cod_pensum', $codPensum)
										->where('cod_inscrip', (int) $codInsLocal)
										->where('numero_cuota', $numeroCuota)
										->first();
									if ($asigSnap && isset($asigSnap->id_asignacion_costo)) {
										$idAsign = (int) $asigSnap->id_asignacion_costo;
									}
								}
								if ($idAsign && Schema::hasColumn('cobro', 'id_asignacion_costo')) {
									DB::table('cobro')->where('nro_cobro', (int) $nroCobro)->update(['id_asignacion_costo' => (int) $idAsign]);
								}
								if ($idAsign && Schema::hasTable('asignacion_mora')) {
									$moraRow = DB::table('asignacion_mora')
										->where('id_asignacion_costo', (int) $idAsign)
										->whereIn('estado', ['PENDIENTE','CONGELADA_PRORROGA','PAUSADA_DUPLICIDAD','CERRADA_SIN_CUOTA','EN_ESPERA'])
										->orderByDesc('id_asignacion_mora')
										->first();
									if (!$moraRow || !isset($moraRow->id_asignacion_mora)) {
										try {
											$estadoPago = strtoupper(trim((string) ($asigSnap->estado_pago ?? '')));
											$fechaPagoCuota = isset($asigSnap->fecha_pago) ? trim((string) $asigSnap->fecha_pago) : '';
											$fechaVenc = isset($asigSnap->fecha_vencimiento) ? trim((string) $asigSnap->fecha_vencimiento) : '';

											$fechaFinCierre = null;
											if ($fechaPagoCuota !== '') {
												$fechaFinCierre = date('Y-m-d', strtotime($fechaPagoCuota));
											} else {
												$fechaFinCierre = substr((string) $fechaCobro, 0, 10);
											}

											if ($estadoPago === 'COBRADO' || $fechaPagoCuota !== '') {
												$semestre = $this->parseSemestreFromCodCurso($codCursoLocal);
												if ($semestre === null) {
													$semestre = $this->resolveSemestreFromSgaRegistroInscripcion($source, $codCeta, $codPensum, $gestion);
												}
												if ($trace) {
													try {
														Log::info('SGA sync cobros multa: resolve semestre for mora cfg', [
															'source' => $source,
															'gestion' => $gestion,
															'cod_ceta' => $codCeta,
															'cod_pensum' => $codPensum,
															'cod_inscrip' => $codInsLocal,
															'numero_cuota' => $numeroCuota,
															'cod_curso_local' => $codCursoLocal,
															'semestre' => $semestre,
															'fecha_fin_cierre' => $fechaFinCierre,
														]);
													} catch (\Throwable $e) {}
												}
												$cfg = null;
												if (Schema::hasTable('datos_mora_detalle') && Schema::hasTable('datos_mora')) {
													try {
														$cfg = DB::table('datos_mora_detalle as dmd')
															->join('datos_mora as dm', 'dm.id_datos_mora', '=', 'dmd.id_datos_mora')
															->where('dm.gestion', $gestion)
															->where('dmd.activo', 1)
															->where('dmd.cuota', (int) $numeroCuota)
															->when($semestre !== null && Schema::hasColumn('datos_mora_detalle', 'semestre'), function ($q) use ($semestre) {
																$q->where('dmd.semestre', (string) $semestre);
															})
															->where(function ($q) use ($codPensum) {
																$q->whereNull('dmd.cod_pensum')->orWhere('dmd.cod_pensum', (string) $codPensum);
															})
															->where(function ($q) use ($fechaFinCierre) {
																$q->whereNull('dmd.fecha_inicio')->orWhere('dmd.fecha_inicio', '<=', $fechaFinCierre);
															})
															->where(function ($q) use ($fechaFinCierre) {
																$q->whereNull('dmd.fecha_fin')->orWhere('dmd.fecha_fin', '>=', $fechaFinCierre);
															})
															->orderByRaw('dmd.cod_pensum IS NULL asc')
															->orderByDesc('dmd.id_datos_mora_detalle')
															->first(['dmd.id_datos_mora_detalle','dmd.monto','dmd.fecha_inicio','dmd.fecha_fin']);
														if ($trace) {
															try {
																Log::info('SGA sync cobros multa: mora cfg selected', [
																	'source' => $source,
																	'gestion' => $gestion,
																	'cod_ceta' => $codCeta,
																	'cod_pensum' => $codPensum,
																	'numero_cuota' => $numeroCuota,
																	'semestre' => $semestre,
																	'cfg_id_datos_mora_detalle' => $cfg ? ($cfg->id_datos_mora_detalle ?? null) : null,
																	'cfg_fecha_inicio' => $cfg ? ($cfg->fecha_inicio ?? null) : null,
																	'cfg_fecha_fin' => $cfg ? ($cfg->fecha_fin ?? null) : null,
																	'cfg_monto' => $cfg ? ($cfg->monto ?? null) : null,
																]);
															} catch (\Throwable $e) {}
														}
													} catch (\Throwable $e) {
														$cfg = null;
													}
												}

												$fechaInicio = null;
												if ($cfg && !empty($cfg->fecha_inicio)) {
													$fechaInicio = date('Y-m-d', strtotime((string) $cfg->fecha_inicio));
												} elseif ($fechaVenc !== '') {
													$fechaInicio = date('Y-m-d', strtotime($fechaVenc . ' +1 day'));
												} elseif ((int) $diasMulta > 0) {
													$daysBack = ((int) $diasMulta) - 1;
													if ($daysBack < 0) { $daysBack = 0; }
													$fechaInicio = date('Y-m-d', strtotime($fechaFinCierre . ' -' . (string) $daysBack . ' day'));
												}
												if ($fechaInicio && $fechaInicio > $fechaFinCierre) {
													$fechaInicio = null;
												}

												$useFallback = (!$cfg || !isset($cfg->id_datos_mora_detalle));
												if ($fechaInicio && (!$useFallback || ((float) $puMulta > 0 && (int) $diasMulta > 0))) {
													$dias = (int) (floor((strtotime($fechaFinCierre) - strtotime($fechaInicio)) / 86400) + 1);
													if ($dias < 1) { $dias = 1; }
													$montoBaseDia = $useFallback ? (float) $puMulta : (float) ($cfg->monto ?? 0);
													$montoMoraCalc = (float) $montoBaseDia * (int) $dias;

													DB::table('asignacion_mora')->insert([
														'id_asignacion_costo' => (int) $idAsign,
														'id_datos_mora_detalle' => $useFallback ? null : (int) ($cfg->id_datos_mora_detalle ?? 0),
														'fecha_inicio_mora' => $fechaInicio,
														'fecha_fin_mora' => $fechaFinCierre,
														'monto_base' => $montoBaseDia,
														'monto_mora' => $montoMoraCalc,
														'monto_descuento' => 0,
														'monto_pagado' => 0,
														'estado' => 'CERRADA_SIN_CUOTA',
														'observaciones' => 'Mora generada por sync (cuota pagada) hasta ' . $fechaFinCierre,
														'created_at' => $now,
														'updated_at' => $now,
													]);

													$moraRow = DB::table('asignacion_mora')
														->where('id_asignacion_costo', (int) $idAsign)
														->where('estado', 'CERRADA_SIN_CUOTA')
														->orderByDesc('id_asignacion_mora')
														->first();
												}
											}
										} catch (\Throwable $e) {
											// no bloquear
										}
									}

									if ($moraRow && isset($moraRow->id_asignacion_mora)) {
										$moraId = (int) $moraRow->id_asignacion_mora;
										$montoMora = (float) ($moraRow->monto_mora ?? 0);
										$descMora = (float) ($moraRow->monto_descuento ?? 0);
										$prevPagado = (float) ($moraRow->monto_pagado ?? 0);
										$neto = max(0, $montoMora - $descMora);
										$newPagado = $prevPagado + (float) $monto;
										$fullNow = ((bool) $cobroCompleto) || ($neto <= 0.0001) || ($newPagado >= ($neto - 0.0001));
										$upd = [
											'monto_pagado' => $newPagado,
											'updated_at' => $now,
										];
										if (Schema::hasColumn('asignacion_mora', 'estado')) {
											$upd['estado'] = $fullNow ? 'PAGADO' : 'PENDIENTE';
										}
										DB::table('asignacion_mora')->where('id_asignacion_mora', (int) $moraId)->update($upd);
									} else {
										$skippedMissingMora++;
									}
								}
							} catch (\Throwable $e) {
								// si falla el update de mora, no bloquea el cobro
							}

							try {
								if ($nroRecibo && Schema::hasTable('recibo')) {
									$recKeys = [
										'nro_recibo' => (int) $nroRecibo,
										'anio' => (int) $anioCobro,
									];
									$recData = [
										'id_usuario' => (string) $idUsuario,
										'id_forma_cobro' => (string) $idFormaCobro,
										'cod_ceta' => $codCeta ?: null,
										'monto_total' => (float) $monto,
										'estado' => 'VIGENTE',
										'updated_at' => $now,
										'created_at' => $now,
									];
									if (Schema::hasColumn('recibo', 'cliente')) {
										$recData['cliente'] = $razon;
									}
									if (Schema::hasColumn('recibo', 'nro_documento_cobro')) {
										$recData['nro_documento_cobro'] = $nroDocumentoPago;
									}
									if (Schema::hasColumn('recibo', 'periodo_facturado')) {
										$recData['periodo_facturado'] = $gestion;
									}
									if (Schema::hasColumn('recibo', 'cod_tipo_doc_identidad')) {
										$recData['cod_tipo_doc_identidad'] = null;
									}
									DB::table('recibo')->updateOrInsert($recKeys, $recData);
								}
							} catch (\Throwable $e) {}

							try {
								if ($nroFactura && Schema::hasTable('factura')) {
									$anioFac = (int) $anioCobro;
									$sucursalFac = $source === 'sga_mec' ? 1 : 0;
									$pvFac = '0';
									$factKeys = [
										'nro_factura' => (int) $nroFactura,
										'anio' => $anioFac,
										'codigo_sucursal' => $sucursalFac,
										'codigo_punto_venta' => (string) $pvFac,
									];
									$factData = [
										'tipo' => 'C',
										'fecha_emision' => $fechaCobro,
										'cod_ceta' => $codCeta ?: null,
										'id_usuario' => (int) $idUsuario,
										'id_forma_cobro' => (string) $idFormaCobro,
										'monto_total' => (float) $monto,
										'estado' => 'VIGENTE',
										'updated_at' => $now,
										'created_at' => $now,
									];
									if (Schema::hasColumn('factura', 'codigo_cufd')) {
										$factData['codigo_cufd'] = null;
									}
									if (Schema::hasColumn('factura', 'cuf')) {
										$factData['cuf'] = null;
									}
									if (Schema::hasColumn('factura', 'periodo_facturado')) {
										$factData['periodo_facturado'] = $gestion;
									}
									if (Schema::hasColumn('factura', 'cliente')) {
										$factData['cliente'] = $razon;
									}
									if (Schema::hasColumn('factura', 'nro_documento_cobro')) {
										$factData['nro_documento_cobro'] = $nroDocumentoPago;
									}
									if (!Schema::hasColumn('factura', 'cod_ceta')) {
										unset($factData['cod_ceta']);
									}
									DB::table('factura')->updateOrInsert($factKeys, $factData);
								}
							} catch (\Throwable $e) {}

							try {
								$usuarioNick = trim((string) ($r->usuario ?? ''));
								if ($usuarioNick === '' && Schema::hasTable('usuarios')) {
									$usuarioNick = (string) (DB::table('usuarios')->where('id_usuario', (int) $idUsuario)->value('nickname') ?? '');
								}
								$detalle = isset($r->concepto) ? trim((string) $r->concepto) : '';
								if ($detalle === '') { $detalle = 'Mora'; }
								$obsOriginal = isset($r->observaciones) ? (string) $r->observaciones : null;
								$fechaNota = substr((string) $fechaCobro, 0, 10);
								$anioFull = $anioCobro;
								$anio2 = (int) date('y', strtotime((string) $fechaCobro));
								$prefijoCarrera = 'E';

								if ($isEfectivo && $nrCorrelativo && Schema::hasTable('nota_reposicion')) {
									$nrData = [
										'correlativo' => (int) $nrCorrelativo,
										'usuario' => $usuarioNick,
										'cod_ceta' => $codCeta,
										'monto' => $monto,
										'concepto_adm' => $detalle,
										'fecha_nota' => $fechaNota,
										'prefijo_carrera' => $prefijoCarrera,
										'anio_reposicion' => $anio2,
										'nro_recibo' => $nroRecibo ? (string) $nroRecibo : null,
										'tipo_ingreso' => null,
									];
									if (Schema::hasColumn('nota_reposicion', 'concepto_est')) {
										$nrData['concepto_est'] = $detalle;
									}
									if (Schema::hasColumn('nota_reposicion', 'observaciones')) {
										$nrData['observaciones'] = $obsOriginal;
									}
									if (Schema::hasColumn('nota_reposicion', 'anulado')) {
										$nrData['anulado'] = false;
									}
									if (Schema::hasColumn('nota_reposicion', 'cont')) {
										$nrData['cont'] = 2;
									}
									DB::table('nota_reposicion')->insert($nrData);
								}

								if ($isBancario && $nbCorrelativo && Schema::hasTable('nota_bancaria')) {
									$fechaDeposito = '';
									try {
										$fechaDeposito = (string) ($r->fecha_deposito ?? $r->fecha_pago ?? '');
										$fechaDeposito = trim($fechaDeposito);
										if ($fechaDeposito !== '') {
											$fechaDeposito = substr((string) date('Y-m-d', strtotime($fechaDeposito)), 0, 10);
										}
									} catch (\Throwable $e) { $fechaDeposito = ''; }
									$nroDeposito = '';
									try {
										$nroDeposito = (string) ($r->nro_deposito ?? $r->nro_transaccion ?? $r->num_deposito ?? $r->deposito ?? '');
										$nroDeposito = trim($nroDeposito);
									} catch (\Throwable $e) { $nroDeposito = ''; }
									$nroCuenta = '';
									try {
										$nroCuenta = (string) ($r->nro_cuenta ?? $r->numero_cuenta ?? $r->cuenta ?? '');
										$nroCuenta = trim($nroCuenta);
									} catch (\Throwable $e) { $nroCuenta = ''; }
									$idCuenta = null;
									$bancoDest = '';
									try {
										if ($nroCuenta !== '' && Schema::hasTable('cuentas_bancarias')) {
											$needle = preg_replace('/\s+/', '', $nroCuenta);
											$cb = DB::table('cuentas_bancarias')
												->whereRaw("REPLACE(REPLACE(REPLACE(TRIM(numero_cuenta), ' ', ''), '-', ''), '.', '') = ?", [
													preg_replace('/\s+/', '', str_replace(['-','.',' '], '', $needle)),
											])
												->first();
											if ($cb) {
												$idCuenta = (int) ($cb->id_cuentas_bancarias ?? 0) ?: null;
												$bancoDest = trim((string) ($cb->banco ?? '')) . ' - ' . trim((string) ($cb->numero_cuenta ?? ''));
											}
										}
									} catch (\Throwable $e) {}

									if ($idCuenta && Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
										DB::table('cobro')->where('nro_cobro', (int) $nroCobro)->update(['id_cuentas_bancarias' => (int) $idCuenta]);
									}

									$nbData = [
										'correlativo' => (int) $nbCorrelativo,
										'usuario' => $usuarioNick,
										'cod_ceta' => $codCeta,
										'monto' => $monto,
										'nro_factura' => $nroFactura ? (string) $nroFactura : '',
										'nro_recibo' => $nroRecibo ? (string) $nroRecibo : '',
										'banco' => $bancoDest,
										'fecha_deposito' => $fechaDeposito,
										'tipo_nota' => (string) $idFormaCobro,
									];
									if (Schema::hasColumn('nota_bancaria', 'anio_deposito')) {
										$nbData['anio_deposito'] = $anioFull;
									}
									if (Schema::hasColumn('nota_bancaria', 'fecha_nota')) {
										$nbData['fecha_nota'] = $fechaNota;
									}
									if (Schema::hasColumn('nota_bancaria', 'concepto')) {
										$nbData['concepto'] = $detalle;
									}
									if (Schema::hasColumn('nota_bancaria', 'nro_transaccion')) {
										$nbData['nro_transaccion'] = $nroDeposito;
									}
									if (Schema::hasColumn('nota_bancaria', 'prefijo_carrera')) {
										$nbData['prefijo_carrera'] = $prefijoCarrera;
									}
									if (Schema::hasColumn('nota_bancaria', 'concepto_est')) {
										$nbData['concepto_est'] = $detalle;
									}
									if (Schema::hasColumn('nota_bancaria', 'observacion')) {
										$nbData['observacion'] = $obsOriginal;
									}
									if (Schema::hasColumn('nota_bancaria', 'anulado')) {
										$nbData['anulado'] = false;
									}
									if (Schema::hasColumn('nota_bancaria', 'banco_origen')) {
										$nbData['banco_origen'] = '';
									}
									if (Schema::hasColumn('nota_bancaria', 'nro_tarjeta')) {
										$nbData['nro_tarjeta'] = null;
									}
									DB::table('nota_bancaria')->insert($nbData);
								}
							} catch (\Throwable $e) {}

							$this->markSyncCobro($source, 'pago_multa', $sourcePk, $codCeta, $codPensum, $gestion, $r, $nroCobro, $anioCobro, false, 'OK', null);
							$inserted++;
						});
					} catch (\Throwable $e) {
						$errors++;
						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						$sourcePk = $this->makePagoMultaSourcePk($r);
						try {
							Log::error('SGA sync cobros multa: insert failed', [
								'source' => $source,
								'gestion' => $gestion,
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'source_pk' => $sourcePk,
								'error' => $e->getMessage(),
							]);
						} catch (\Throwable $e2) {}
						if ($sourcePk !== '') {
							$this->markSyncCobro($source, 'pago_multa', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), ($dryRun ? 'DRY_ERROR' : 'ERROR'), $e->getMessage());
						}
					}
				}
			});

		return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion','skippedMissingMora');
	}

	public function syncCobrosRezagadosPorGestion(string $source, string $gestion, int $chunk = 1000, bool $dryRun = false, ?int $codCetaFilter = null, ?string $codPensumFilter = null, bool $trace = false): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$gestion = trim((string) $gestion);
		$total = 0; $inserted = 0; $skipped = 0; $errors = 0;
		$skippedSynced = 0; $skippedMissingUser = 0; $skippedMissingInscripcion = 0;

		if ($gestion === '') {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}
		if (!Schema::hasTable('cobro') || !Schema::hasTable('sga_sync_cobros') || !Schema::hasTable('rezagados')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}
		if (!Schema::connection($source)->hasTable('rezagados')) {
			return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
		}

		$defaultUserId = (int) (env('SYNC_DEFAULT_USER_ID', 1));
		$carreraLabel = $source === 'sga_elec' ? 'Electricidad y Electrónica Automotriz' : 'Mecánica Automotriz';
		try {
			Log::info('SGA sync cobros rezagados: mode', [
				'source' => $source,
				'gestion' => $gestion,
				'dry_run' => $dryRun,
				'trace' => $trace,
				'writes_cobro' => !$dryRun,
				'writes_sga_sync_cobros' => (!$dryRun) || $trace,
				'default_user_id' => $defaultUserId,
			]);
		} catch (\Throwable $e) {}

		$codTipoCobroRezag = $this->resolveCodTipoCobro('REZAGADOS');

		$baseQuery = DB::connection($source)
			->table('rezagados as rz')
			->join('registro_inscripcion as ri', function ($join) {
				$join->on('ri.cod_inscrip', '=', 'rz.cod_inscrip');
			})
			->where('ri.gestion', $gestion)
			->when($codCetaFilter !== null, function ($q) use ($codCetaFilter) {
				$q->where('rz.cod_ceta', (int) $codCetaFilter);
			})
			->when($codPensumFilter !== null && trim((string)$codPensumFilter) !== '', function ($q) use ($codPensumFilter) {
				$q->where('rz.cod_pensum', (string) $codPensumFilter);
			});

		$baseQuery
			->select('rz.*')
			->orderBy('rz.cod_ceta')
			->orderBy('rz.cod_pensum')
			->orderBy('rz.cod_inscrip')
			->orderBy('rz.num_rezagado')
			->orderBy('rz.num_pago_rezagado')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$skipped, &$errors, &$skippedSynced, &$skippedMissingUser, &$skippedMissingInscripcion, $dryRun, $trace, $source, $gestion, $defaultUserId, $carreraLabel, $codTipoCobroRezag) {
				$total += count($rows);
				if (empty($rows)) { return; }

				$now = now();
				$sourcePks = [];
				$nicknames = [];
				foreach ($rows as $r) {
					$sourcePk = $this->makeRezagadoSourcePk($r);
					if ($sourcePk !== '') { $sourcePks[] = $sourcePk; }
					$userNick = trim((string) ($r->usuario ?? ''));
					if ($userNick !== '') { $nicknames[] = mb_substr($userNick, 0, 40); }
				}
				$sourcePks = array_values(array_unique(array_filter($sourcePks)));
				$nicknames = array_values(array_unique(array_filter($nicknames)));

				$already = [];
				if (!empty($sourcePks)) {
					$syncQuery = DB::table('sga_sync_cobros')
						->where('source_conn', $source)
						->where('source_table', 'rezagados')
						->whereIn('source_pk', $sourcePks);
					if (!$dryRun) {
						$syncQuery->where('status', 'OK');
					}
					$rowsSynced = $syncQuery->pluck('source_pk')->all();
					foreach ($rowsSynced as $pk) { $already[(string)$pk] = true; }
				}

				$userMap = [];
				if (!empty($nicknames)) {
					$uRows = DB::table('usuarios')->whereIn('nickname', $nicknames)->select('id_usuario','nickname')->get();
					foreach ($uRows as $u) { $userMap[(string)$u->nickname] = (int) $u->id_usuario; }
				}

				foreach ($rows as $r) {
					try {
						$sourcePk = $this->makeRezagadoSourcePk($r);
						if ($sourcePk === '') { $skipped++; continue; }
						if (isset($already[$sourcePk])) { $skippedSynced++; continue; }

						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						if ($codCeta <= 0 || $codPensum === '') { $skipped++; continue; }

						$tipoIns = strtoupper(trim((string) ($r->kardex_economico ?? '')));
						if ($tipoIns === '') { $tipoIns = 'NORMAL'; }

						$userNick = trim((string) ($r->usuario ?? ''));
						$idUsuario = $defaultUserId;
						if ($userNick !== '' && isset($userMap[$userNick])) {
							$idUsuario = (int) $userMap[$userNick];
						} else {
							$skippedMissingUser++;
						}

						$idFormaCobro = $this->resolveFormaCobro((string) ($r->code_tipo_pago ?? ''));
						$numRezagado = (int) ($r->num_rezagado ?? 0);
						$numPagoRezagado = (int) ($r->num_pago_rezagado ?? 0);
						$monto = (float) ($r->monto ?? 0);
						$cobroCompleto = (bool) ($r->pago_completo ?? true);
						$nroRecibo = isset($r->num_comprobante) ? (int) $r->num_comprobante : null;
						$nroFactura = isset($r->num_factura) ? (int) $r->num_factura : null;
						$materia = isset($r->materia) ? trim((string) $r->materia) : null;
						if ($materia === '') { $materia = null; }
						$parcial = isset($r->parcial) ? trim((string) $r->parcial) : null;
						if ($parcial === '') { $parcial = null; }

						$razon = null;
						try {
							$razon = trim((string) ($r->razon ?? $r->razon_social ?? $r->cliente ?? $r->nombre_cliente ?? $r->nombre ?? ''));
							if ($razon === '') { $razon = null; }
						} catch (\Throwable $e) { $razon = null; }
						$nroDocumentoPago = null;
						try {
							$nroDocumentoPago = trim((string) ($r->nro_documento_pago ?? $r->nro_documento ?? $r->nit ?? $r->documento_cliente ?? $r->numero_documento ?? ''));
							if ($nroDocumentoPago === '') { $nroDocumentoPago = null; }
						} catch (\Throwable $e) { $nroDocumentoPago = null; }

						$fechaPago = isset($r->fecha_pago) ? $r->fecha_pago : null;
						$anioCobro = $fechaPago ? (int) date('Y', strtotime((string) $fechaPago)) : (int) date('Y');
						$fechaCobro = $fechaPago ? date('Y-m-d H:i:s', strtotime((string)$fechaPago)) : date('Y-m-d H:i:s');

						if ($dryRun) {
							$this->markSyncCobro($source, 'rezagados', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), 'DRY_OK', null);
							$inserted++;
							continue;
						}

						$insQ = DB::table('inscripciones')
							->where('carrera', $carreraLabel)
							->where('cod_ceta', (int) $codCeta)
							->where('cod_pensum', (string) $codPensum)
							->where('gestion', (string) $gestion);
						if (Schema::hasColumn('inscripciones', 'tipo_inscripcion')) {
							$insQ->where('tipo_inscripcion', $tipoIns);
						}
						$insRow = $insQ->orderByDesc('cod_inscrip')->first(['cod_inscrip']);
						if (!$insRow || !isset($insRow->cod_inscrip)) {
							$skippedMissingInscripcion++;
							$this->markSyncCobro($source, 'rezagados', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), 'ERROR', 'No existe inscripcion local para cod_ceta/cod_pensum/gestion/tipo_inscripcion');
							continue;
						}
						$codInsLocal = (int) $insRow->cod_inscrip;

						$nroCobro = $this->nextDocCounter('COBRO:' . $anioCobro);
						$isEfectivo = ($idFormaCobro === 'E');
						$isBancario = in_array($idFormaCobro, ['B','C','D','L','O'], true);
						$nrCorrelativo = null;
						$nbCorrelativo = null;
						if ($isEfectivo && Schema::hasTable('nota_reposicion')) {
							$nrCorrelativo = $this->nextDocCounter('NOTA_REPOSICION');
						}
						if ($isBancario && Schema::hasTable('nota_bancaria')) {
							$nbCorrelativo = $this->nextDocCounter('NOTA_BANCARIA');
						}

						DB::transaction(function () use ($source, $gestion, $now, $anioCobro, $nroCobro, $fechaCobro, $codCeta, $codPensum, $tipoIns, $codInsLocal, $monto, $cobroCompleto, $idUsuario, $idFormaCobro, $nroFactura, $nroRecibo, $r, $sourcePk, $nrCorrelativo, $nbCorrelativo, $isEfectivo, $isBancario, $razon, $nroDocumentoPago, $numRezagado, $numPagoRezagado, $codTipoCobroRezag, $materia, $parcial, &$inserted) {
							$cobroData = [
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'tipo_inscripcion' => $tipoIns,
								'cod_inscrip' => $codInsLocal,
								'id_cuota' => null,
								'gestion' => $gestion,
								'nro_cobro' => $nroCobro,
								'anio_cobro' => $anioCobro,
								'monto' => $monto,
								'fecha_cobro' => $fechaCobro,
								'cobro_completo' => $cobroCompleto,
								'observaciones' => isset($r->observaciones) ? (string) $r->observaciones : null,
								'concepto' => isset($r->concepto) ? (string) $r->concepto : null,
								'cod_tipo_cobro' => $codTipoCobroRezag,
								'id_usuario' => $idUsuario,
								'id_forma_cobro' => $idFormaCobro,
								'tipo_documento' => null,
								'medio_doc' => null,
								'pu_mensualidad' => 0,
								'order' => $numRezagado,
								'descuento' => 0,
								'id_cuentas_bancarias' => null,
								'nro_factura' => $nroFactura,
								'nro_recibo' => $nroRecibo,
								'id_item' => null,
								'id_asignacion_costo' => null,
								'created_at' => $now,
								'updated_at' => $now,
							];
								if (Schema::hasColumn('cobro', 'qr_alias')) {
									$cobroData['qr_alias'] = null;
								}
								if (Schema::hasColumn('cobro', 'reposicion_factura')) {
									$cobroData['reposicion_factura'] = false;
								}
								if (!Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
									unset($cobroData['id_cuentas_bancarias']);
								}
								DB::table('cobro')->insert($cobroData);

								DB::table('rezagados')->updateOrInsert([
									'cod_inscrip' => (int) $codInsLocal,
									'num_rezagado' => (int) $numRezagado,
									'num_pago_rezagado' => (int) $numPagoRezagado,
								], [
									'num_factura' => $nroFactura,
									'num_recibo' => $nroRecibo,
									'fecha_pago' => $fechaCobro,
									'monto' => (float) $monto,
									'pago_completo' => (bool) $cobroCompleto,
									'observaciones' => isset($r->observaciones) ? (string) $r->observaciones : null,
									'usuario' => (int) $idUsuario,
									'materia' => $materia,
									'parcial' => $parcial,
									'updated_at' => $now,
									'created_at' => DB::raw('COALESCE(created_at, NOW())'),
								]);

								try {
									if ($nroRecibo && Schema::hasTable('recibo')) {
										$recKeys = [
											'nro_recibo' => (int) $nroRecibo,
											'anio' => (int) $anioCobro,
										];
										$recData = [
											'id_usuario' => (string) $idUsuario,
											'id_forma_cobro' => (string) $idFormaCobro,
											'cod_ceta' => $codCeta ?: null,
											'monto_total' => (float) $monto,
											'estado' => 'VIGENTE',
											'updated_at' => $now,
											'created_at' => $now,
										];
										if (Schema::hasColumn('recibo', 'cliente')) {
											$recData['cliente'] = $razon;
										}
										if (Schema::hasColumn('recibo', 'nro_documento_cobro')) {
											$recData['nro_documento_cobro'] = $nroDocumentoPago;
										}
										if (Schema::hasColumn('recibo', 'periodo_facturado')) {
											$recData['periodo_facturado'] = $gestion;
										}
										if (Schema::hasColumn('recibo', 'cod_tipo_doc_identidad')) {
											$recData['cod_tipo_doc_identidad'] = null;
										}
										DB::table('recibo')->updateOrInsert($recKeys, $recData);
									}
								} catch (\Throwable $e) {}

								try {
									if ($nroFactura && Schema::hasTable('factura')) {
										$anioFac = (int) $anioCobro;
										$sucursalFac = $source === 'sga_mec' ? 1 : 0;
										$pvFac = '0';
										$factKeys = [
											'nro_factura' => (int) $nroFactura,
											'anio' => $anioFac,
											'codigo_sucursal' => $sucursalFac,
											'codigo_punto_venta' => (string) $pvFac,
										];
										$factData = [
											'tipo' => 'C',
											'fecha_emision' => $fechaCobro,
											'cod_ceta' => $codCeta ?: null,
											'id_usuario' => (int) $idUsuario,
											'id_forma_cobro' => (string) $idFormaCobro,
											'monto_total' => (float) $monto,
											'estado' => 'VIGENTE',
											'updated_at' => $now,
											'created_at' => $now,
										];
										if (Schema::hasColumn('factura', 'codigo_cufd')) {
											$factData['codigo_cufd'] = null;
										}
										if (Schema::hasColumn('factura', 'cuf')) {
											$factData['cuf'] = null;
										}
										if (Schema::hasColumn('factura', 'periodo_facturado')) {
											$factData['periodo_facturado'] = $gestion;
										}
										if (Schema::hasColumn('factura', 'cliente')) {
											$factData['cliente'] = $razon;
										}
										if (Schema::hasColumn('factura', 'nro_documento_cobro')) {
											$factData['nro_documento_cobro'] = $nroDocumentoPago;
										}
										if (!Schema::hasColumn('factura', 'cod_ceta')) {
											unset($factData['cod_ceta']);
										}
										DB::table('factura')->updateOrInsert($factKeys, $factData);
									}
								} catch (\Throwable $e) {}

								try {
									$usuarioNick = trim((string) ($r->usuario ?? ''));
									if ($usuarioNick === '' && Schema::hasTable('usuarios')) {
										$usuarioNick = (string) (DB::table('usuarios')->where('id_usuario', (int) $idUsuario)->value('nickname') ?? '');
									}
									$detalle = isset($r->concepto) ? trim((string) $r->concepto) : '';
									if ($detalle === '') { $detalle = 'Rezagado'; }
									$obsOriginal = isset($r->observaciones) ? (string) $r->observaciones : null;
									$fechaNota = substr((string) $fechaCobro, 0, 10);
									$anioFull = $anioCobro;
									$anio2 = (int) date('y', strtotime((string) $fechaCobro));
									$prefijoCarrera = 'E';

									if ($isEfectivo && $nrCorrelativo && Schema::hasTable('nota_reposicion')) {
										$nrData = [
											'correlativo' => (int) $nrCorrelativo,
											'usuario' => $usuarioNick,
											'cod_ceta' => $codCeta,
											'monto' => $monto,
											'concepto_adm' => $detalle,
											'fecha_nota' => $fechaNota,
											'prefijo_carrera' => $prefijoCarrera,
											'anio_reposicion' => $anio2,
											'nro_recibo' => $nroRecibo ? (string) $nroRecibo : null,
											'tipo_ingreso' => null,
										];
										if (Schema::hasColumn('nota_reposicion', 'concepto_est')) {
											$nrData['concepto_est'] = $detalle;
										}
										if (Schema::hasColumn('nota_reposicion', 'observaciones')) {
											$nrData['observaciones'] = $obsOriginal;
										}
										if (Schema::hasColumn('nota_reposicion', 'anulado')) {
											$nrData['anulado'] = false;
										}
										if (Schema::hasColumn('nota_reposicion', 'cont')) {
											$nrData['cont'] = 2;
										}
										DB::table('nota_reposicion')->insert($nrData);
									}

									if ($isBancario && $nbCorrelativo && Schema::hasTable('nota_bancaria')) {
										$fechaDeposito = '';
										try {
											$fechaDeposito = (string) ($r->fecha_deposito ?? $r->fecha_pago ?? '');
											$fechaDeposito = trim($fechaDeposito);
											if ($fechaDeposito !== '') {
												$fechaDeposito = substr((string) date('Y-m-d', strtotime($fechaDeposito)), 0, 10);
											}
										} catch (\Throwable $e) { $fechaDeposito = ''; }
										$nroDeposito = '';
										try {
											$nroDeposito = (string) ($r->nro_deposito ?? $r->nro_transaccion ?? $r->num_deposito ?? $r->deposito ?? '');
											$nroDeposito = trim($nroDeposito);
										} catch (\Throwable $e) { $nroDeposito = ''; }
										$nroCuenta = '';
										try {
											$nroCuenta = (string) ($r->nro_cuenta ?? $r->numero_cuenta ?? $r->cuenta ?? '');
											$nroCuenta = trim($nroCuenta);
										} catch (\Throwable $e) { $nroCuenta = ''; }
										$idCuenta = null;
										$bancoDest = '';
										try {
											if ($nroCuenta !== '' && Schema::hasTable('cuentas_bancarias')) {
												$needle = preg_replace('/\s+/', '', $nroCuenta);
												$cb = DB::table('cuentas_bancarias')
													->whereRaw("REPLACE(REPLACE(REPLACE(TRIM(numero_cuenta), ' ', ''), '-', ''), '.', '') = ?", [
														preg_replace('/\s+/', '', str_replace(['-','.',' '], '', $needle)),
												])
													->first();
												if ($cb) {
													$idCuenta = (int) ($cb->id_cuentas_bancarias ?? 0) ?: null;
													$bancoDest = trim((string) ($cb->banco ?? '')) . ' - ' . trim((string) ($cb->numero_cuenta ?? ''));
												}
											}
										} catch (\Throwable $e) {}

										if ($idCuenta && Schema::hasColumn('cobro', 'id_cuentas_bancarias')) {
											DB::table('cobro')->where('nro_cobro', (int) $nroCobro)->update(['id_cuentas_bancarias' => (int) $idCuenta]);
										}

										$nbData = [
											'correlativo' => (int) $nbCorrelativo,
											'usuario' => $usuarioNick,
											'cod_ceta' => $codCeta,
											'monto' => $monto,
											'nro_factura' => $nroFactura ? (string) $nroFactura : '',
											'nro_recibo' => $nroRecibo ? (string) $nroRecibo : '',
											'banco' => $bancoDest,
											'fecha_deposito' => $fechaDeposito,
											'tipo_nota' => (string) $idFormaCobro,
										];
										if (Schema::hasColumn('nota_bancaria', 'anio_deposito')) {
											$nbData['anio_deposito'] = $anioFull;
										}
										if (Schema::hasColumn('nota_bancaria', 'fecha_nota')) {
											$nbData['fecha_nota'] = $fechaNota;
										}
										if (Schema::hasColumn('nota_bancaria', 'concepto')) {
											$nbData['concepto'] = $detalle;
										}
										if (Schema::hasColumn('nota_bancaria', 'nro_transaccion')) {
											$nbData['nro_transaccion'] = $nroDeposito;
										}
										if (Schema::hasColumn('nota_bancaria', 'prefijo_carrera')) {
											$nbData['prefijo_carrera'] = $prefijoCarrera;
										}
										if (Schema::hasColumn('nota_bancaria', 'concepto_est')) {
											$nbData['concepto_est'] = $detalle;
										}
										if (Schema::hasColumn('nota_bancaria', 'observacion')) {
											$nbData['observacion'] = $obsOriginal;
										}
										if (Schema::hasColumn('nota_bancaria', 'anulado')) {
											$nbData['anulado'] = false;
										}
										if (Schema::hasColumn('nota_bancaria', 'banco_origen')) {
											$nbData['banco_origen'] = '';
										}
										if (Schema::hasColumn('nota_bancaria', 'nro_tarjeta')) {
											$nbData['nro_tarjeta'] = null;
										}
										DB::table('nota_bancaria')->insert($nbData);
									}
								} catch (\Throwable $e) {}

								$this->markSyncCobro($source, 'rezagados', $sourcePk, $codCeta, $codPensum, $gestion, $r, $nroCobro, $anioCobro, false, 'OK', null);
								$inserted++;
						});
					} catch (\Throwable $e) {
						$errors++;
						$codCeta = (int) ($r->cod_ceta ?? 0);
						$codPensum = trim((string) ($r->cod_pensum ?? ''));
						$sourcePk = $this->makeRezagadoSourcePk($r);
						try {
							Log::error('SGA sync cobros rezagados: insert failed', [
								'source' => $source,
								'gestion' => $gestion,
								'cod_ceta' => $codCeta,
								'cod_pensum' => $codPensum,
								'source_pk' => $sourcePk,
								'error' => $e->getMessage(),
							]);
						} catch (\Throwable $e2) {}
						if ($sourcePk !== '') {
							$this->markSyncCobro($source, 'rezagados', $sourcePk, $codCeta, $codPensum, $gestion, $r, null, null, ($dryRun && !$trace), ($dryRun ? 'DRY_ERROR' : 'ERROR'), $e->getMessage());
						}
					}
				}
			});

		return compact('source','gestion','total','inserted','skipped','errors','skippedSynced','skippedMissingUser','skippedMissingInscripcion');
	}

	private function makePagoSourcePk($r): string
	{
		$codCeta = (string) (isset($r->cod_ceta) ? $r->cod_ceta : '');
		$codPensum = (string) (isset($r->cod_pensum) ? $r->cod_pensum : '');
		$codIns = (string) (isset($r->cod_inscrip) ? $r->cod_inscrip : '');
		$kardex = (string) (isset($r->kardex_economico) ? $r->kardex_economico : '');
		$numCuota = (string) (isset($r->num_cuota) ? $r->num_cuota : '');
		$numPago = (string) (isset($r->num_pago) ? $r->num_pago : '');
		$pk = trim($codCeta) . '|' . trim($codPensum) . '|' . trim($codIns) . '|' . trim($kardex) . '|' . trim($numCuota) . '|' . trim($numPago);
		return $pk === '|||||' ? '' : $pk;
	}

	private function makeRezagadoSourcePk($r): string
	{
		$codCeta = (string) (isset($r->cod_ceta) ? $r->cod_ceta : '');
		$codPensum = (string) (isset($r->cod_pensum) ? $r->cod_pensum : '');
		$codIns = (string) (isset($r->cod_inscrip) ? $r->cod_inscrip : '');
		$kardex = (string) (isset($r->kardex_economico) ? $r->kardex_economico : '');
		$numRz = (string) (isset($r->num_rezagado) ? $r->num_rezagado : '');
		$numPagoRz = (string) (isset($r->num_pago_rezagado) ? $r->num_pago_rezagado : '');
		$pk = trim($codCeta) . '|' . trim($codPensum) . '|' . trim($codIns) . '|' . trim($kardex) . '|' . trim($numRz) . '|' . trim($numPagoRz);
		return $pk === '|||||' ? '' : $pk;
	}

	private function resolveCodTipoCobro(string $codTipoCobro): ?string
	{
		$cod = trim((string) $codTipoCobro);
		if ($cod === '') { return null; }

		static $cache = [];
		if (array_key_exists($cod, $cache)) {
			return $cache[$cod];
		}

		try {
			if (Schema::hasTable('tipo_cobro')) {
				$exists = DB::table('tipo_cobro')->where('cod_tipo_cobro', $cod)->exists();
				$cache[$cod] = $exists ? $cod : null;
				return $cache[$cod];
			}
		} catch (\Throwable $e) {}

		$cache[$cod] = null;
		return null;
	}

	private function makePagoMultaSourcePk($r): string
	{
		$codCeta = (string) (isset($r->cod_ceta) ? $r->cod_ceta : '');
		$codPensum = (string) (isset($r->cod_pensum) ? $r->cod_pensum : '');
		$gestion = (string) (isset($r->gestion) ? $r->gestion : '');
		$kardex = (string) (isset($r->kardex_economico) ? $r->kardex_economico : '');
		$numCuota = (string) (isset($r->num_cuota) ? $r->num_cuota : '');
		$numPago = (string) (isset($r->num_pago) ? $r->num_pago : '');
		$pk = trim($codCeta) . '|' . trim($codPensum) . '|' . trim($gestion) . '|' . trim($kardex) . '|' . trim($numCuota) . '|' . trim($numPago);
		return $pk === '|||||' ? '' : $pk;
	}

	private function parseSemestreFromCodCurso(?string $codCurso): ?string
	{
		$raw = trim((string) $codCurso);
		if ($raw === '') { return null; }

		$parts = explode('-', $raw);
		$suffix = trim((string) end($parts));
		if ($suffix === '') { return null; }

		$first = substr($suffix, 0, 1);
		if ($first === false || $first === '') { return null; }
		if (!preg_match('/^[1-9]$/', $first)) { return null; }
		return (string) $first;
	}

	private function resolveSemestreFromSgaRegistroInscripcion(string $source, int $codCeta, string $codPensum, string $gestion): ?string
	{
		$codPensum = trim((string) $codPensum);
		$gestion = trim((string) $gestion);
		if ($codCeta <= 0 || $codPensum === '' || $gestion === '') { return null; }
		try {
			if (!Schema::connection($source)->hasTable('registro_inscripcion')) { return null; }
			$codCurso = DB::connection($source)
				->table('registro_inscripcion')
				->where('cod_ceta', (int) $codCeta)
				->where('cod_pensum', (string) $codPensum)
				->where('gestion', (string) $gestion)
				->orderByDesc('cod_inscrip')
				->value('cod_curso');
			return $this->parseSemestreFromCodCurso(is_string($codCurso) ? $codCurso : null);
		} catch (\Throwable $e) {
			return null;
		}
	}

	private function resolveFormaCobro(string $codeTipoPago): string
	{
		$raw = strtoupper(trim($codeTipoPago));
		if ($raw === '') { return 'E'; }

		$map = [
			'EF' => 'E',
			'E' => 'E',
			'EFECTIVO' => 'E',
			'B' => 'B',
			'TR' => 'B',
			'TRANSFERENCIA' => 'B',
			'TRANSFERENCIA' => 'B',
			'QR' => 'B',
			'QRPAGO' => 'B',
			'TC' => 'L',
			'L' => 'L',
			'TARJETA' => 'L',
			'CH' => 'C',
			'C' => 'C',
			'CHEQUE' => 'C',
			'DEP' => 'D',
			'D' => 'D',
			'DEPOSITO' => 'D',
			'DEPÓSITO' => 'D',
			'O' => 'O',
			'OTRO' => 'O',
			'T' => 'T',
			'TRASPASO' => 'T',
		];
		$code = $map[$raw] ?? $raw;
		try {
			if (Schema::hasTable('formas_cobro')) {
				$exists = DB::table('formas_cobro')->where('id_forma_cobro', $code)->exists();
				if ($exists) { return $code; }
			}
		} catch (\Throwable $e) {}
		return 'E';
	}

	private function resolveCuotaId(string $gestion, string $codPensum, string $tipoInscripcion, int $numCuota): ?int
	{
		if ($numCuota <= 0 || !Schema::hasTable('cuotas')) { return null; }
		$tipoIns = strtoupper(trim($tipoInscripcion));
		$nombre = 'Mensualidad ' . $numCuota;
		$tipoCandidates = [];
		if ($tipoIns === 'ARRASTRE') {
			$tipoCandidates = (array) config('hydra_assign.cuotas_tipo_map.materia', []);
			$tipoCandidates[] = 'ARRASTRE';
			$tipoCandidates[] = 'MATERIA';
		} else {
			$tipoCandidates[] = 'MENSUALIDAD';
			$tipoCandidates[] = 'costo_mensual';
			$tipoCandidates[] = 'COSTO_MENSUAL';
		}
		$tipoCandidates = array_values(array_unique(array_filter(array_map(function ($s) {
			return trim((string) $s);
		}, $tipoCandidates))));

		$q = DB::table('cuotas')->where('nombre', $nombre);
		if (Schema::hasColumn('cuotas', 'gestion') && trim((string) $gestion) !== '') {
			$q->where('gestion', $gestion);
		}
		if (Schema::hasColumn('cuotas', 'cod_pensum') && trim((string) $codPensum) !== '') {
			$q->where('cod_pensum', $codPensum);
		}
		if (Schema::hasColumn('cuotas', 'tipo') && !empty($tipoCandidates)) {
			$q->whereIn(DB::raw('LOWER(tipo)'), array_map('strtolower', $tipoCandidates));
		}
		$id = $q->value('id_cuota');
		if ($id) { return (int) $id; }

		$id = DB::table('cuotas')->where('nombre', $nombre)->value('id_cuota');
		return $id ? (int) $id : null;
	}

	private function nextDocCounter(string $scope): int
	{
		$cn = DB::connection();
		$cn->statement(
			"INSERT INTO doc_counter (scope, last, created_at, updated_at) VALUES (?, 1, NOW(), NOW())\n"
			. "ON DUPLICATE KEY UPDATE last = LAST_INSERT_ID(last + 1), updated_at = NOW()",
			[$scope]
		);

		$pdo = $cn->getPdo();
		$stmt = $pdo->query('SELECT LAST_INSERT_ID() AS id');
		$row = $stmt ? $stmt->fetch(
			\PDO::FETCH_ASSOC
		) : null;
		$id = $row && isset($row['id']) ? (int) $row['id'] : 0;
		return $id;
	}

	private function markSyncCobro(string $sourceConn, string $sourceTable, string $sourcePk, int $codCeta, string $codPensum, string $gestion, $srcRow, ?int $localNroCobro, ?int $localAnioCobro, bool $dryRun, string $status, ?string $errorMessage): void
	{
		if ($dryRun) { return; }
		$payload = [
			'source_conn' => $sourceConn,
			'source_table' => $sourceTable,
			'source_pk' => $sourcePk,
			'cod_ceta' => $codCeta ?: null,
			'cod_pensum' => $codPensum !== '' ? $codPensum : null,
			'gestion' => $gestion,
			'fecha_pago' => isset($srcRow->fecha_pago) ? $srcRow->fecha_pago : null,
			'monto' => isset($srcRow->monto) ? (float)$srcRow->monto : null,
			'descuento' => isset($srcRow->descuento) ? (float)$srcRow->descuento : null,
			'local_nro_cobro' => $localNroCobro,
			'local_anio_cobro' => $localAnioCobro,
			'local_cobro_uid' => ($localAnioCobro && $localNroCobro) ? (((string)$localAnioCobro) . '-' . ((string)$localNroCobro)) : null,
			'status' => $status,
			'error_message' => $errorMessage,
			'hash_payload' => hash('sha256', json_encode($srcRow)),
			'synced_at' => now(),
			'updated_at' => now(),
			'created_at' => now(),
		];

		DB::table('sga_sync_cobros')->updateOrInsert(
			[
				'source_conn' => $sourceConn,
				'source_table' => $sourceTable,
				'source_pk' => $sourcePk,
			],
			$payload
		);
	}

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
		$carreraLabel = $this->getCarreraLabel($source);
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

				$map = $this->mapPensumsByCarrera($this->extractColumnValues($rows, 'cod_pensum', 50), $carreraLabel);

				// 2) Preparar filas candidatas y aplicar regla anti-duplicado NORMAL/ARRASTRE
				$prepared = [];
				$keysToCheck = [];
				foreach ($rows as $r) {
					$codInsSga = (int) ($r->cod_inscrip ?? 0);
					$codCeta = (int) ($r->cod_ceta ?? 0);
					if ($codInsSga === 0 || $codCeta === 0) { continue; }

					$codPensumSga = $this->substr((string) ($r->cod_pensum ?? ''), 50);
					if ($codPensumSga === '' || !isset($map[$codPensumSga])) {
						$skipped++;
						continue; // no hay mapeo de pensum -> evitar violación de FK
					}

					$codPensumLocal = $this->substr((string) $map[$codPensumSga], 50);
					$gestion = $this->substr((string) ($r->gestion ?? ''), 20);
					$codCurso = trim((string) ($r->cod_curso ?? ''));
					$tipoIns = $this->normalizeTipoInscripcion($r->tipo_inscripcion ?? null);
					$dedupeKey = $this->buildInscripcionDedupeKey($codCeta, $codPensumLocal, $gestion, $codCurso);

					$prepared[] = [
						'carrera'             => $carreraLabel,
						'source_cod_inscrip'  => $codInsSga,
						'id_usuario'          => $defaultUserId,
						'cod_ceta'            => $codCeta,
						'cod_pensum'          => $codPensumLocal,
						'cod_pensum_sga'      => $codPensumSga,
						'cod_curso'           => $codCurso,
						'gestion'             => $gestion,
						'tipo_estudiante'     => $this->substrNull($r->tipo_estudiante ?? null, 20),
						'fecha_inscripcion'   => $this->toDate($r->fecha_inscripcion ?? null),
						'tipo_inscripcion'    => $tipoIns,
						'created_at'          => $now,
						'updated_at'          => $now,
						'dedupe_key'          => $dedupeKey,
					];

					$keysToCheck[$dedupeKey] = [
						'cod_ceta' => $codCeta,
						'cod_pensum' => $codPensumLocal,
						'gestion' => $gestion,
						'cod_curso' => $codCurso,
					];
				}

				$arrastreKeysInChunk = [];
				foreach ($prepared as $row) {
					if (($row['tipo_inscripcion'] ?? '') === 'ARRASTRE') {
						$arrastreKeysInChunk[$row['dedupe_key']] = true;
					}
				}

				$arrastreKeysInDb = $this->loadArrastreDedupeKeys($keysToCheck, $carreraLabel);

				foreach ($prepared as $row) {
					$dedupeKey = (string) ($row['dedupe_key'] ?? '');
					$isNormal = (($row['tipo_inscripcion'] ?? '') === 'NORMAL');
					$hasSiblingArrastre = isset($arrastreKeysInChunk[$dedupeKey]) || isset($arrastreKeysInDb[$dedupeKey]);

					if ($isNormal && $hasSiblingArrastre) {
						$skipped++;
						Log::warning('syncInscripciones: NORMAL omitida por duplicidad con ARRASTRE', [
							'cod_ceta' => $row['cod_ceta'] ?? null,
							'cod_pensum' => $row['cod_pensum'] ?? null,
							'gestion' => $row['gestion'] ?? null,
							'cod_curso' => $row['cod_curso'] ?? null,
							'source_cod_inscrip' => $row['source_cod_inscrip'] ?? null,
							'carrera' => $row['carrera'] ?? null,
						]);
						continue;
					}

					unset($row['dedupe_key']);
					$payload[] = $row;
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
								'beca' => true,
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
						$mapped = array_map(function($d){
							return [
								'nombre_beca' => (string)$d['nombre_descuento'],
								'descripcion' => (string)$d['descripcion'],
								'monto' => (int)$d['monto'],
								'porcentaje' => (bool)$d['porcentaje'],
								'estado' => (bool)$d['estado'],
								'beca' => false,
							];
						}, $descuentos);

						$names = array_values(array_unique(array_map(function($x){ return (string)$x['nombre_beca']; }, $mapped)));
						$existing = \Illuminate\Support\Facades\DB::table('def_descuentos_beca')
							->whereIn('nombre_beca', $names)
							->pluck('cod_beca','nombre_beca')
							->all();

						$toInsert = [];
						$toUpdate = [];
						foreach ($mapped as $m) {
							$key = (string)$m['nombre_beca'];
							if (isset($existing[$key])) {
								$toUpdate[] = array_merge($m, ['cod_beca' => (int)$existing[$key]]);
							} else {
								$toInsert[] = $m;
							}
						}
						if (!empty($toInsert)) {
							\Illuminate\Support\Facades\DB::table('def_descuentos_beca')->insert($toInsert);
						}
						if (!empty($toUpdate)) {
							foreach (array_chunk($toUpdate, 1000) as $chunkRows) {
								foreach ($chunkRows as $u) {
									$id = (int)$u['cod_beca']; unset($u['cod_beca']);
									\Illuminate\Support\Facades\DB::table('def_descuentos_beca')->where('cod_beca', $id)->update($u);
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
		$defaultCodigoCarrera = $source === 'sga_elec' ? 'EEA' : 'MEA';
		// Resolver codigo_carrera por código (evita problemas por nombres con tildes/variantes)
		$codigoCarrera = DB::table('carrera')->where('codigo_carrera', $defaultCodigoCarrera)->value('codigo_carrera');
		if (!$codigoCarrera) {
			$codigoCarrera = $source === 'sga_elec' ? env('CARRERA_CODE_ELEC', $defaultCodigoCarrera) : env('CARRERA_CODE_MEC', $defaultCodigoCarrera);
		}

		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;

		$sgaConn = DB::connection($source);
		$sgaTable = $sgaConn->getSchemaBuilder()->hasTable('pensum') ? 'pensum' : 'registro_inscripcion';

		$sgaConn
			->table($sgaTable)
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

	private function getCarreraLabel(string $source): string
	{
		return $source === 'sga_elec'
			? 'Electricidad y Electrónica Automotriz'
			: 'Mecánica Automotriz';
	}

	private function extractColumnValues(iterable $rows, string $column, ?int $maxLen = null): array
	{
		$values = [];
		foreach ($rows as $row) {
			$value = trim((string) ($row->{$column} ?? ''));
			if ($value === '') {
				continue;
			}

			$values[] = $maxLen ? mb_substr($value, 0, $maxLen) : $value;
		}

		return array_values(array_unique($values));
	}

	private function mapPensumsByCarrera(array $sgaPensums, string $carreraLabel): array
	{
		if (empty($sgaPensums)) {
			return [];
		}

		$map = [];
		$existingLocal = DB::table('pensums')->whereIn('cod_pensum', $sgaPensums)->pluck('cod_pensum')->all();
		foreach ($existingLocal as $codPensum) {
			$map[$codPensum] = $codPensum;
		}

		$pairs = DB::table('pensum_map')
			->where('carrera', $carreraLabel)
			->whereIn('cod_pensum_sga', $sgaPensums)
			->pluck('cod_pensum_local', 'cod_pensum_sga')
			->all();

		return array_replace($map, $pairs);
	}

	private function normalizeTipoInscripcion($tipo): string
	{
		$tipo = strtoupper(trim((string) $tipo));
		return $tipo === 'ARRASTRE' ? 'ARRASTRE' : 'NORMAL';
	}

	private function buildInscripcionDedupeKey(int $codCeta, string $codPensum, string $gestion, string $codCurso): string
	{
		return implode('|', [(string) $codCeta, $codPensum, $gestion, trim($codCurso)]);
	}

	private function loadArrastreDedupeKeys(array $keysToCheck, string $carreraLabel): array
	{
		if (empty($keysToCheck)) {
			return [];
		}

		$codCetaSet = [];
		$codPensumSet = [];
		$gestionSet = [];
		$codCursoSet = [];
		foreach ($keysToCheck as $key) {
			$codCetaSet[] = (int) $key['cod_ceta'];
			$codPensumSet[] = (string) $key['cod_pensum'];
			$gestionSet[] = (string) $key['gestion'];
			$codCursoSet[] = (string) $key['cod_curso'];
		}

		$query = DB::table('inscripciones')
			->where('carrera', $carreraLabel)
			->where('tipo_inscripcion', 'ARRASTRE')
			->whereIn('cod_ceta', array_values(array_unique($codCetaSet)))
			->whereIn('cod_pensum', array_values(array_unique($codPensumSet)))
			->whereIn('gestion', array_values(array_unique($gestionSet)));

		$codCursoSet = array_values(array_unique($codCursoSet));
		if (!empty($codCursoSet)) {
			$query->whereIn('cod_curso', $codCursoSet);
		}

		$keys = [];
		foreach ($query->select('cod_ceta', 'cod_pensum', 'gestion', 'cod_curso')->get() as $row) {
			$keys[$this->buildInscripcionDedupeKey(
				(int) $row->cod_ceta,
				(string) $row->cod_pensum,
				(string) $row->gestion,
				(string) ($row->cod_curso ?? '')
			)] = true;
		}

		return $keys;
	}

	private function resolveKardexTipoColumn(): ?string
	{
		if (Schema::hasColumn('kardex_notas', 'tipo_inscripcion')) {
			return 'tipo_inscripcion';
		}

		if (Schema::hasColumn('kardex_notas', 'tipo_incripcion')) {
			return 'tipo_incripcion';
		}

		return null;
	}

	private function resolveSgaKardexTipo($row): string
	{
		return $this->normalizeTipoInscripcion(
			$row->tipo_inscripcion
				?? $row->tipo_incripcion
				?? $row->kardex
				?? null
		);
	}

	private function loadLocalInscripcionesBySource(array $sourceIds, string $carreraLabel): array
	{
		$sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
		if (empty($sourceIds)) {
			return [];
		}

		$grouped = [];
		$rows = DB::table('inscripciones')
			->select('source_cod_inscrip', 'cod_inscrip', 'cod_pensum', 'tipo_inscripcion')
			->where('carrera', $carreraLabel)
			->whereIn('source_cod_inscrip', $sourceIds)
			->orderBy('cod_inscrip')
			->get();

		foreach ($rows as $row) {
			$grouped[(int) $row->source_cod_inscrip][] = [
				'cod_inscrip' => (int) $row->cod_inscrip,
				'cod_pensum' => (string) $row->cod_pensum,
				'tipo_inscripcion' => $this->normalizeTipoInscripcion($row->tipo_inscripcion ?? null),
			];
		}

		return $grouped;
	}

	private function pickLocalInscripcion(array $options, string $tipoInscripcion, string $codPensum): ?array
	{
		if (empty($options)) {
			return null;
		}

		$tipoInscripcion = $this->normalizeTipoInscripcion($tipoInscripcion);
		$scored = [];
		foreach ($options as $option) {
			$score = 0;
			if (($option['cod_pensum'] ?? null) === $codPensum) {
				$score += 2;
			}
			if (($option['tipo_inscripcion'] ?? null) === $tipoInscripcion) {
				$score += 4;
			}

			$option['_score'] = $score;
			$scored[] = $option;
		}

		usort($scored, function ($left, $right) {
			if ($left['_score'] === $right['_score']) {
				return ($left['cod_inscrip'] ?? 0) <=> ($right['cod_inscrip'] ?? 0);
			}

			return ($right['_score'] ?? 0) <=> ($left['_score'] ?? 0);
		});

		$selected = $scored[0] ?? null;
		if (!$selected || ($selected['_score'] ?? 0) <= 0) {
			return null;
		}

		unset($selected['_score']);
		return $selected;
	}

	private function buildKardexRowKey(array $row, string $colTipo): string
	{
		return implode('|', [
			(string) $row['cod_ceta'],
			(string) $row['cod_pensum'],
			(string) $row['cod_inscrip'],
			(string) $row[$colTipo],
			(string) $row['cod_kardex'],
		]);
	}

	private function splitKardexPayload(array $row, string $colTipo): array
	{
		$identity = [
			'cod_ceta' => $row['cod_ceta'],
			'cod_pensum' => $row['cod_pensum'],
			'cod_inscrip' => $row['cod_inscrip'],
			$colTipo => $row[$colTipo],
			'cod_kardex' => $row['cod_kardex'],
		];

		$values = [
			'sigla_materia' => $row['sigla_materia'],
			'observacion' => $row['observacion'],
			'id_usuario' => $row['id_usuario'],
			'updated_at' => $row['updated_at'],
		];

		if (array_key_exists('created_at', $row)) {
			$values['created_at'] = $row['created_at'];
		}

		return [$identity, $values];
	}

	private function loadExistingKardexKeys(array $payload, string $colTipo): array
	{
		if (empty($payload)) {
			return [];
		}

		$codCetas = [];
		$codPensums = [];
		$codInscrips = [];
		$codKardexes = [];
		foreach ($payload as $row) {
			$codCetas[] = (int) $row['cod_ceta'];
			$codPensums[] = (string) $row['cod_pensum'];
			$codInscrips[] = (int) $row['cod_inscrip'];
			$codKardexes[] = (int) $row['cod_kardex'];
		}

		$existing = [];
		$rows = DB::table('kardex_notas')
			->select('cod_ceta', 'cod_pensum', 'cod_inscrip', 'cod_kardex', $colTipo)
			->whereIn('cod_ceta', array_values(array_unique($codCetas)))
			->whereIn('cod_pensum', array_values(array_unique($codPensums)))
			->whereIn('cod_inscrip', array_values(array_unique($codInscrips)))
			->whereIn('cod_kardex', array_values(array_unique($codKardexes)))
			->get();

		foreach ($rows as $row) {
			$existing[$this->buildKardexRowKey([
				'cod_ceta' => $row->cod_ceta,
				'cod_pensum' => $row->cod_pensum,
				'cod_inscrip' => $row->cod_inscrip,
				$colTipo => $row->{$colTipo},
				'cod_kardex' => $row->cod_kardex,
			], $colTipo)] = true;
		}

		return $existing;
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

	/**
	 * Obtiene datos de estudiante desde la base de datos SGA correcta según su pensum
	 * Evita consultar ambas bases de datos innecesariamente
	 *
	 * @param int $codCeta Código del estudiante
	 * @return object|null Datos del estudiante o null si no se encuentra
	 */
	public function getEstudianteOptimizado($codCeta)
	{
		try {
			// Determinar la conexión correcta según el pensum del estudiante
			$connection = \App\Helpers\SgaHelper::getConnectionByCeta($codCeta);

			// Consultar solo en la base de datos correspondiente
			$estudiante = DB::connection($connection)
				->table('estudiante')
				->where('cod_ceta', $codCeta)
				->first();

			if ($estudiante) {
				return $estudiante;
			}

			// Si no se encuentra, intentar en la otra base de datos como fallback
			$otherConnection = $connection === 'sga_elec' ? 'sga_mec' : 'sga_elec';
			return DB::connection($otherConnection)
				->table('estudiante')
				->where('cod_ceta', $codCeta)
				->first();
		} catch (\Throwable $e) {
			\Log::warning('SgaSyncRepository: Error al obtener estudiante optimizado', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
			return null;
		}
	}

	/**
	 * Obtiene inscripciones de estudiante desde la base de datos SGA correcta según su pensum
	 *
	 * @param int $codCeta Código del estudiante
	 * @param string|null $codPensum Código del pensum (opcional, para optimizar más)
	 * @return \Illuminate\Support\Collection Colección de inscripciones
	 */
	public function getInscripcionesOptimizado($codCeta, $codPensum = null)
	{
		try {
			// Si se proporciona pensum, usar ese para determinar la conexión
			if ($codPensum) {
				$connection = \App\Helpers\SgaHelper::getConnectionByPensum($codPensum);
			} else {
				// Si no, determinar por CETA
				$connection = \App\Helpers\SgaHelper::getConnectionByCeta($codCeta);
			}

			// Consultar solo en la base de datos correspondiente
			$inscripciones = DB::connection($connection)
				->table('registro_inscripcion')
				->where('cod_ceta', $codCeta)
				->orderBy('gestion', 'desc')
				->get();

			if ($inscripciones->isNotEmpty()) {
				return $inscripciones;
			}

			// Si no se encuentra, intentar en la otra base de datos como fallback
			$otherConnection = $connection === 'sga_elec' ? 'sga_mec' : 'sga_elec';
			return DB::connection($otherConnection)
				->table('registro_inscripcion')
				->where('cod_ceta', $codCeta)
				->orderBy('gestion', 'desc')
				->get();
		} catch (\Throwable $e) {
			\Log::warning('SgaSyncRepository: Error al obtener inscripciones optimizado', [
				'cod_ceta' => $codCeta,
				'cod_pensum' => $codPensum,
				'error' => $e->getMessage()
			]);
			return collect([]);
		}
	}

	public function syncKardexNotas(string $source, int $chunk = 1000, bool $dryRun = false, ?string $gestion = null): array
	{
		$source = in_array($source, ['sga_elec','sga_mec']) ? $source : 'sga_elec';
		$carreraLabel = $this->getCarreraLabel($source);
		$total = 0; $inserted = 0; $updated = 0; $skipped = 0;
		$chunkNumber = 0;

		if (!Schema::hasTable('kardex_notas')) {
			return compact('source','total','inserted','updated','skipped');
		}

		$colTipo = $this->resolveKardexTipoColumn();

		if (!$colTipo) {
			\Log::warning('SgaSyncRepository: kardex_notas no tiene columna tipo_inscripcion/tipo_incripcion');
			return compact('source','total','inserted','updated','skipped');
		}

		$inscripcionesGestion = [];
		if ($gestion) {
			$inscripcionesGestion = DB::connection($source)
				->table('registro_inscripcion')
				->where('gestion', $gestion)
				->pluck('cod_inscrip')
				->toArray();
			\Log::info("[KARDEX SYNC {$carreraLabel}] Filtrando por gestión {$gestion}: " . count($inscripcionesGestion) . " inscripciones encontradas");
		}

		$query = DB::connection($source)->table('kardex_notas');

		if (!empty($inscripcionesGestion)) {
			$query->whereIn('cod_inscrip', $inscripcionesGestion);
		}

		$query->orderBy('cod_ceta')
			->orderBy('cod_inscrip')
			->orderBy('cod_kardex')
			->orderBy('sigla_materia')
			->chunk($chunk, function ($rows) use (&$total, &$inserted, &$updated, &$skipped, &$chunkNumber, $dryRun, $carreraLabel, $colTipo) {
				$chunkNumber++;
				$total += count($rows);
				$now = now();
				$payload = [];
				$chunkSkipped = 0;
				$localInscripciones = $this->loadLocalInscripcionesBySource(
					array_map('intval', $this->extractColumnValues($rows, 'cod_inscrip')),
					$carreraLabel
				);
				$pensumMap = $this->mapPensumsByCarrera($this->extractColumnValues($rows, 'cod_pensum', 50), $carreraLabel);

				$materiasExistentes = [];
				$materias = DB::table('materia')->select('cod_pensum', 'sigla_materia')->get();
				foreach ($materias as $m) {
					$materiasExistentes[$m->cod_pensum . '|' . $m->sigla_materia] = true;
				}

				$payloadByKey = [];
				$duplicateKeysInChunk = [];
				$firstRow = $rows[0] ?? null;
				$lastRow = $rows[count($rows) - 1] ?? null;

				\Log::info("[KARDEX SYNC {$carreraLabel}] Procesando chunk {$chunkNumber}", [
					'rows' => count($rows),
					'first' => $firstRow ? [
						'cod_ceta' => (int) ($firstRow->cod_ceta ?? 0),
						'cod_inscrip' => (int) ($firstRow->cod_inscrip ?? 0),
						'cod_kardex' => (int) ($firstRow->cod_kardex ?? 0),
						'sigla_materia' => (string) ($firstRow->sigla_materia ?? ''),
					] : null,
					'last' => $lastRow ? [
						'cod_ceta' => (int) ($lastRow->cod_ceta ?? 0),
						'cod_inscrip' => (int) ($lastRow->cod_inscrip ?? 0),
						'cod_kardex' => (int) ($lastRow->cod_kardex ?? 0),
						'sigla_materia' => (string) ($lastRow->sigla_materia ?? ''),
					] : null,
				]);

				foreach ($rows as $r) {
					$codKardex = (int) ($r->cod_kardex ?? 0);
					$codCeta = (int) ($r->cod_ceta ?? 0);
					$codInsSga = (int) ($r->cod_inscrip ?? 0);
					$codPensumSga = $this->substr((string) ($r->cod_pensum ?? ''), 50);
					$siglaMat = $this->substr((string) ($r->sigla_materia ?? ''), 20);
					$tipoInsSga = $this->resolveSgaKardexTipo($r);

					if ($codKardex === 0 || $codCeta === 0 || $siglaMat === '') {
						$skipped++;
						$chunkSkipped++;
						continue;
					}

					if (!isset($localInscripciones[$codInsSga])) {
						$skipped++;
						$chunkSkipped++;
						\Log::warning("[KARDEX SYNC {$carreraLabel}] Inscripción local no encontrada", [
							'cod_ceta' => $codCeta,
							'source_cod_inscrip' => $codInsSga,
							'cod_kardex' => $codKardex,
							'sigla_materia' => $siglaMat,
						]);
						continue;
					}

					if ($codPensumSga === '' || !isset($pensumMap[$codPensumSga])) {
						$skipped++;
						$chunkSkipped++;
						\Log::warning("[KARDEX SYNC {$carreraLabel}] Pensum no mapeado", [
							'cod_ceta' => $codCeta,
							'source_cod_inscrip' => $codInsSga,
							'cod_pensum_sga' => $codPensumSga,
							'cod_kardex' => $codKardex,
							'sigla_materia' => $siglaMat,
						]);
						continue;
					}

					$codPensumLocal = $pensumMap[$codPensumSga];
					$inscripcionLocal = $this->pickLocalInscripcion($localInscripciones[$codInsSga], $tipoInsSga, $codPensumLocal);
					if (!$inscripcionLocal) {
						$skipped++;
						$chunkSkipped++;
						\Log::warning("[KARDEX SYNC {$carreraLabel}] No se pudo resolver inscripción local", [
							'cod_ceta' => $codCeta,
							'source_cod_inscrip' => $codInsSga,
							'cod_pensum_local' => $codPensumLocal,
							'tipo_sga' => $tipoInsSga,
							'cod_kardex' => $codKardex,
							'sigla_materia' => $siglaMat,
						]);
						continue;
					}

					$codInscripLocal = (int) $inscripcionLocal['cod_inscrip'];
					$tipoInscripLocal = (string) $inscripcionLocal['tipo_inscripcion'];

					if (!isset($materiasExistentes[$codPensumLocal . '|' . $siglaMat])) {
						$skipped++;
						$chunkSkipped++;
						\Log::warning("[KARDEX SYNC {$carreraLabel}] Materia no existe localmente", [
							'cod_ceta' => $codCeta,
							'source_cod_inscrip' => $codInsSga,
							'cod_inscrip_local' => $codInscripLocal,
							'cod_pensum_local' => $codPensumLocal,
							'cod_kardex' => $codKardex,
							'sigla_materia' => $siglaMat,
						]);
						continue;
					}

					$rowPayload = [
						'cod_kardex'      => $codKardex,
						'cod_ceta'        => $codCeta,
						'cod_pensum'      => $codPensumLocal,
						'cod_inscrip'     => $codInscripLocal,
						'sigla_materia'   => $siglaMat,
						$colTipo          => $tipoInscripLocal,
						'observacion'     => $this->substrNull($r->observacion ?? null, 255),
						'id_usuario'      => (int) ($r->id_usuario ?? 1),
						'created_at'      => $now,
						'updated_at'      => $now,
					];

					$rowKey = $this->buildKardexRowKey($rowPayload, $colTipo);
					if (isset($payloadByKey[$rowKey])) {
						$duplicateKeysInChunk[$rowKey] = true;
					}
					$payloadByKey[$rowKey] = $rowPayload;
				}

				$payload = array_values($payloadByKey);

				if (!empty($duplicateKeysInChunk)) {
					\Log::warning("[KARDEX SYNC {$carreraLabel}] Claves duplicadas detectadas dentro del chunk", [
						'chunk' => $chunkNumber,
						'count' => count($duplicateKeysInChunk),
						'keys' => array_keys($duplicateKeysInChunk),
					]);
				}

				if ($dryRun || empty($payload)) {
					\Log::info("[KARDEX SYNC {$carreraLabel}] Chunk {$chunkNumber} sin persistencia", [
						'dry_run' => $dryRun,
						'payload' => count($payload),
						'skipped' => $chunkSkipped,
					]);
					return;
				}

				$existingKeys = $this->loadExistingKardexKeys($payload, $colTipo);

				DB::table('kardex_notas')->upsert(
					$payload,
					['cod_ceta','cod_pensum','cod_inscrip',$colTipo,'cod_kardex'],
					['cod_ceta','cod_pensum','cod_inscrip','sigla_materia',$colTipo,'observacion','id_usuario','updated_at']
				);

				$persistedKeys = $this->loadExistingKardexKeys($payload, $colTipo);
				$missingRows = [];
				foreach ($payload as $rowPayload) {
					$rowKey = $this->buildKardexRowKey($rowPayload, $colTipo);
					if (!isset($persistedKeys[$rowKey])) {
						$missingRows[$rowKey] = $rowPayload;
					}
				}

				if (!empty($missingRows)) {
					\Log::warning("[KARDEX SYNC {$carreraLabel}] Filas no persistidas tras upsert masivo, aplicando fallback individual", [
						'chunk' => $chunkNumber,
						'count' => count($missingRows),
						'keys' => array_keys($missingRows),
					]);

					foreach ($missingRows as $rowKey => $rowPayload) {
						try {
							[$identity, $values] = $this->splitKardexPayload($rowPayload, $colTipo);
							DB::table('kardex_notas')->updateOrInsert($identity, $values);
						} catch (\Throwable $e) {
							\Log::warning("[KARDEX SYNC {$carreraLabel}] Fallback individual falló", [
								'chunk' => $chunkNumber,
								'key' => $rowKey,
								'row' => $rowPayload,
								'error' => $e->getMessage(),
							]);
						}
					}

					$persistedKeys = $this->loadExistingKardexKeys($payload, $colTipo);
					$stillMissing = [];
					foreach ($payload as $rowPayload) {
						$rowKey = $this->buildKardexRowKey($rowPayload, $colTipo);
						if (!isset($persistedKeys[$rowKey])) {
							$stillMissing[$rowKey] = $rowPayload;
						}
					}

					if (!empty($stillMissing)) {
						\Log::error("[KARDEX SYNC {$carreraLabel}] Filas siguen ausentes después del fallback individual", [
							'chunk' => $chunkNumber,
							'count' => count($stillMissing),
							'rows' => array_values($stillMissing),
						]);
					}
				}

				$updatedCount = count($existingKeys);
				$insertedCount = max(0, count($payload) - $updatedCount);
				$updated += $updatedCount;
				$inserted += $insertedCount;

				\Log::info("[KARDEX SYNC {$carreraLabel}] Chunk {$chunkNumber} persistido", [
					'payload' => count($payload),
					'updated' => $updatedCount,
					'inserted' => $insertedCount,
					'skipped' => $chunkSkipped,
				]);
			});

		return compact('source','total','inserted','updated','skipped');
	}
}
