<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use App\Models\Inscripcion;
use App\Models\Cuota;

class AssignCostoSemestralFromInscripcion implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public $payload;

	public function __construct(array $payload)
	{
		$this->payload = $payload;
	}

	public function handle(): void
	{
		$codPensum = $this->payload['cod_pensum'] ?? null;
		$gestion = $this->payload['gestion'] ?? null;
		$codCurso = (string) ($this->payload['cod_curso'] ?? '');
		$tipoIns = strtoupper((string) ($this->payload['tipo_inscripcion'] ?? ''));
		$codInscrip = (string) ($this->payload['cod_inscrip'] ?? '');

		Log::info('AssignCosto Job start', [
			'payload' => [
				'cod_inscrip' => $codInscrip,
				'cod_pensum' => $codPensum,
				'gestion' => $gestion,
				'cod_curso' => $codCurso,
				'tipo_inscripcion' => $tipoIns,
			],
		]);

		// Tomar cod_pensum desde la tabla inscripciones como fuente primaria
		$ins = Inscripcion::query()->find($codInscrip);
		if (!$ins) {
			Log::warning('AsignacionCostos Job: inscripcion no encontrada, abortando para evitar FK', [
				'cod_inscrip' => $codInscrip,
			]);
			return;
		}
		if ($ins && !empty($ins->cod_pensum)) {
			$codPensum = $ins->cod_pensum; // override desde BD
		}
		// Usar siempre los valores persistidos en BD para coherencia y evitar fallos de FK
		$codInscrip = (string) ($ins->cod_inscrip ?? $codInscrip);
		$gestion = $ins->gestion ?? $gestion;
		$codCurso = $ins->cod_curso ?? $codCurso;

		Log::info('AssignCosto Job context', [
			'cod_inscrip' => $codInscrip,
			'cod_pensum' => $codPensum,
			'gestion' => $gestion,
			'cod_curso' => $codCurso,
			'tipo_inscripcion' => $tipoIns,
		]);

		// Configuración dinámica (config/hydra_assign.php)
		$tipoCostoMap = (array) config('hydra_assign.tipo_costo_map', []);
		$turnoMapCfg = (array) config('hydra_assign.turno_map', []);
		$fallbackAnyTipo = (bool) config('hydra_assign.fallback_any_tipo', false);
		$forbiddenWhenNormal = array_map('strtolower', (array) config('hydra_assign.forbidden_when_normal', []));

		if (!$codPensum || !$gestion || !$codCurso || !$codInscrip) return;

		// Derivar semestre y turno a partir de cod_curso (p.ej.: 04-MTZ-101M => sem 1, turno M)
		$segment = $codCurso;
		if (str_contains($codCurso, '-')) {
			$parts = explode('-', $codCurso);
			$segment = end($parts) ?: $codCurso;
		}
		$segment = trim($segment);
		$turnoChar = strtoupper(substr($segment, -1));
		// Soportar variantes con/sin acento desde config
		$turnoCandidates = $turnoMapCfg[$turnoChar] ?? [];
		$turno = $turnoCandidates[0] ?? null; // preferida
		// Extraer primer dígito como semestre, p.ej. 101M => 1
		$digits = preg_replace('/\D+/', '', $segment);
		$semestre = $digits !== '' ? intval(substr($digits, 0, 1)) : null;

		// Si faltan turno/semestre, para NORMAL abortar; para ARRASTRE continuamos con fallback
		if ((!$turno || !$semestre) && $tipoIns !== 'ARRASTRE') return;

		// Mapear tipo_inscripcion -> lista de tipo_costo (preferencias)
		$tipoCostoCandidates = $tipoCostoMap[$tipoIns]
			?: ($tipoCostoMap['NORMAL'] ?? ['Costo Mensual','Costo Semestral']);

		// A partir de ahora, usamos exclusivamente el cod_pensum de la inscripción
		$pensumExact = $codPensum;

		// Normalizar candidatos de tipo_costo a lower y con '_' (DB puede tener espacios)
		$tipoCostoCandidatesLower = array_map('strtolower', $tipoCostoCandidates);
		$tipoCostoCandidatesNorm = array_map(function($s){ return str_replace(' ', '_', $s); }, $tipoCostoCandidatesLower);

		// Buscar costo semestral SOLO en el pensum de la inscripción
		$costo = null; $matchedPensum = null; $matchedTipo = null;
		foreach ($tipoCostoCandidatesNorm as $tipoLowerNorm) {
			$qc = CostoSemestral::query()
				->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
				->whereRaw('TRIM(gestion) = ?', [trim((string)$gestion)])
				->whereRaw("REPLACE(LOWER(tipo_costo), ' ', '_') = ?", [$tipoLowerNorm]);
			if ($semestre) { $qc->where('semestre', $semestre); }
			if (!empty($turnoCandidates)) { $qc->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates)); }
			$costo = $qc->first();
			if ($costo) { $matchedPensum = $pensumExact; $matchedTipo = $costo->tipo_costo; break; }
		}
		// Fallback opcional ignorando tipo_costo
		if (!$costo && $fallbackAnyTipo) {
			$q = CostoSemestral::query()
				->where('cod_pensum', $pensumExact)
				->where('gestion', $gestion)
				->where('semestre', $semestre)
				->whereIn('turno', $turnoCandidates);
			// Evitar tipos prohibidos cuando es NORMAL
			if ($tipoIns === 'NORMAL' && !empty($forbiddenWhenNormal)) {
				$placeholders = implode(',', array_fill(0, count($forbiddenWhenNormal), '?'));
				$q->whereRaw('LOWER(tipo_costo) NOT IN ('.$placeholders.')', $forbiddenWhenNormal);
			}
			// Ordenar preferidos primero si existen con distintas variantes
			if (!empty($tipoCostoCandidatesLower)) {
				$place = implode(',', array_fill(0, count($tipoCostoCandidatesLower), '?'));
				$q->orderByRaw('FIELD(LOWER(tipo_costo), '.$place.') DESC', $tipoCostoCandidatesLower);
			}
			$costo = $q->first();
			if ($costo) { $matchedPensum = $pensumExact; $matchedTipo = $costo->tipo_costo; }
		}
		// Fallback específico para ARRASTRE: ignorar semestre/turno si no hay match estricto
		if (!$costo && $tipoIns === 'ARRASTRE') {
			$costo = CostoSemestral::query()
				->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
				->whereRaw('TRIM(gestion) = ?', [trim((string)$gestion)])
				->whereRaw('LOWER(tipo_costo) = ?', ['materia'])
				->orderBy('id_costo_semestral')
				->first();
			if ($costo) { $matchedPensum = $pensumExact; $matchedTipo = $costo->tipo_costo; }
			Log::info('Costo ARRASTRE fallback (ignorar semestre/turno)', [
				'found' => (bool) $costo,
				'cod_inscrip' => $codInscrip,
				'pensum' => $pensumExact,
				'gestion' => $gestion,
			]);
		}
		// Fallback específico para NORMAL: ignorar semestre/turno si no hay match estricto
		if (!$costo && $tipoIns === 'NORMAL') {
			// Preferir 'costo_mensual', pero si hay otros candidatos úsalos en orden
			$candidates = $tipoCostoCandidatesNorm;
			if (!in_array('costo_mensual', $candidates, true)) {
				array_unshift($candidates, 'costo_mensual');
			}
			foreach ($candidates as $tipoLowerNorm) {
				$costo = CostoSemestral::query()
					->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
					->whereRaw('TRIM(gestion) = ?', [trim((string)$gestion)])
					->whereRaw("REPLACE(LOWER(tipo_costo), ' ', '_') = ?", [$tipoLowerNorm])
					->orderBy('id_costo_semestral')
					->first();
				if ($costo) { $matchedPensum = $pensumExact; $matchedTipo = $costo->tipo_costo; break; }
			}
			Log::info('Costo NORMAL fallback (ignorar semestre/turno)', [
				'found' => (bool) $costo,
				'cod_inscrip' => $codInscrip,
				'pensum' => $pensumExact,
				'gestion' => $gestion,
				'candidates' => $candidates,
			]);
		}
		if (!$costo) {
			Log::warning('No se encontró costo_semestral (gestión exacta requerida, no se ignora gestion)', [
				'cod_inscrip' => $codInscrip,
				'cod_pensum' => $codPensum,
				'gestion' => $gestion,
				'semestre' => $semestre,
				'turno_candidates' => $turnoCandidates,
				'tipo_costo_candidates' => $tipoCostoCandidates,
				'fallback_any_tipo' => $fallbackAnyTipo,
				'pensum_exact' => $pensumExact,
				'forbidden_when_normal' => $forbiddenWhenNormal,
			]);
			return;
		}

		// Forzar pensum exacto al del costo encontrado para asegurar consistencia con plantillas
		$pensumExact = (string) ($costo->cod_pensum ?? $pensumExact);

		// Determinar multiplicador por materias de ARRASTRE registradas en kardex_notas
		$materiasArrastre = 0; $multiplier = 1;
		$fallbackArrastreCount = (int) ($this->payload['arrastre_count'] ?? 0);
		try {
			if ($tipoIns === 'ARRASTRE' && Schema::hasTable('kardex_notas')) {
				$colTipo = null;
				if (Schema::hasColumn('kardex_notas', 'tipo_inscripcion')) { $colTipo = 'tipo_inscripcion'; }
				elseif (Schema::hasColumn('kardex_notas', 'tipo_incripcion')) { $colTipo = 'tipo_incripcion'; }
				if ($colTipo) {
					$materiasArrastre = (int) DB::table('kardex_notas')
						->where('cod_inscrip', (int) $codInscrip)
						->where($colTipo, 'ARRASTRE')
						->count();
				}
			}
		} catch (\Throwable $e) { /* no-op */ }
		// Usar el mayor entre lo almacenado y el enviado por payload como fallback
		if ($tipoIns === 'ARRASTRE') {
			$materiasArrastre = max($materiasArrastre, $fallbackArrastreCount);
			$multiplier = max(1, $materiasArrastre);
		}

		// Generación de cuotas en asignacion_costos (Opción C)
		$cuotasTipoMap = (array) config('hydra_assign.cuotas_tipo_map', []);
		$cuotasDefaults = (array) config('hydra_assign.cuotas_defaults', []);
		$tipoCostoLower = strtolower((string) $costo->tipo_costo);
		$tipoCostoText = preg_replace('/\s+/', ' ', trim($tipoCostoLower));
		$tipoCostoKey = str_replace(' ', '_', $tipoCostoText);
		if (str_contains($tipoCostoText, 'materia')) { $tipoCostoKey = 'materia'; }
		elseif (str_contains($tipoCostoText, 'mensual')) { $tipoCostoKey = 'costo_mensual'; }
		elseif (str_contains($tipoCostoText, 'semestral')) { $tipoCostoKey = 'costo_semestral'; }
		$cuotaTipos = (array) ($cuotasTipoMap[$tipoCostoKey] ?? $cuotasTipoMap[$tipoCostoKey] ?? []);

		// 1) Intentar plantilla de cuotas por contexto SOLO en el pensum de la inscripción
		$plantilla = collect();
		$plantillaPensum = null;
		Log::info('Cuotas plantilla: lookup params', [
			'cod_inscrip' => $codInscrip,
			'pensum_exact' => $pensumExact,
			'gestion' => $gestion,
			'semestre' => $semestre,
			'turno_candidates' => $turnoCandidates,
			'cuota_tipos' => $cuotaTipos,
			'costo_tipo_raw' => $costo->tipo_costo,
			'costo_tipo_key' => $tipoCostoKey,
            'arrastre_count' => $materiasArrastre,
            'arrastre_multiplier' => $multiplier,
		]);

		$qCuota = Cuota::query()
			->whereRaw('TRIM(gestion) = ?', [trim((string)$gestion)])
			->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
			->where('semestre', $semestre)
			->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates));
		if (!empty($cuotaTipos)) {
			$qCuota->whereIn(DB::raw('LOWER(tipo)'), array_map('strtolower', $cuotaTipos));
		}
		$plantilla = $qCuota->orderBy('fecha_vencimiento')->orderBy('id_cuota')->get();
		Log::info('Cuotas plantilla: found rows', [
			'count' => $plantilla->count(),
			'ids' => $plantilla->pluck('id_cuota'),
			'pensums' => $plantilla->pluck('cod_pensum')->unique()->values(),
		]);
		if ($plantilla->count() > 0) {
			$plantillaPensum = $pensumExact;
		}

		$created = 0; $updated = 0; $rows = []; $processed = 0; $tplIds = [];
		if ($plantilla->count() > 0) {
			$idx = 0;
			foreach ($plantilla as $tpl) {
				// Seguridad extra: solo procesar plantillas cuyo pensum coincide exactamente
				if (trim((string)$tpl->cod_pensum) !== trim((string)$pensumExact)) {
					continue;
				}
				$idx++;
				$tplIds[] = $tpl->id_cuota;
				$vals = [
					'monto' => ((float) $tpl->monto) * $multiplier, // multiplicar por materias arrastre si aplica
					'estado' => true,
					'fecha_vencimiento' => $tpl->fecha_vencimiento,
					'estado_pago' => 'pendiente',
					'monto_pagado' => 0,
					'id_cuota_template' => $tpl->id_cuota,
				];
				$unique = [
					'cod_pensum' => $pensumExact,
					'cod_inscrip' => (int) $codInscrip,
					'id_costo_semestral' => $costo->id_costo_semestral,
					'numero_cuota' => $idx,
				];
				$model = AsignacionCostos::query()->where($unique)->first();
				if ($model) {
					$model->fill($vals);
					$model->save();
					$updated++;
				} else {
					$model = AsignacionCostos::create($unique + $vals);
					$created++;
					$rows[] = $model->id_asignacion_costo;
				}
				$processed++;
			}
			if ($processed > 0) {
				// Completar cuotas faltantes hasta el deseado (defaults)
				$desired = max(1, (int)($cuotasDefaults['count'] ?? 5));
				$firstDay = (int)($cuotasDefaults['first_due_day'] ?? 15);
				$intervalDays = (int)($cuotasDefaults['interval_days'] ?? 30);
				$useCalendarMonth = (bool)($cuotasDefaults['use_calendar_month'] ?? true);

				$filled = 0;
				// Para ARRASTRE: si hubo plantilla, NO rellenar cuotas adicionales
				if ($tipoIns !== 'ARRASTRE' && $processed < $desired) {
					// Base: última fecha de plantilla aceptada o fecha_inscripcion/now
					$lastDue = null;
					foreach ($plantilla as $tpl) {
						if (trim((string)$tpl->cod_pensum) === trim((string)$pensumExact) && !empty($tpl->fecha_vencimiento)) {
							$ld = \Illuminate\Support\Carbon::parse($tpl->fecha_vencimiento);
							if (!$lastDue || $ld->greaterThan($lastDue)) { $lastDue = $ld; }
						}
					}
					$baseDate = $lastDue ?: ($ins && !empty($ins->fecha_inscripcion) ? \Illuminate\Support\Carbon::parse($ins->fecha_inscripcion) : \Illuminate\Support\Carbon::now());

					for ($i = 1; $i <= ($desired - $processed); $i++) {
						if ($useCalendarMonth) {
							$due = (clone $baseDate)->addMonths($i)->day($firstDay);
						} else {
							$due = (clone $baseDate)->addDays($i * max(1, $intervalDays));
						}
						$idx = $processed + $i;
						$vals = [
							'monto' => ((float) $costo->monto_semestre) * $multiplier,
							'estado' => true,
							'fecha_vencimiento' => $due->toDateString(),
							'estado_pago' => 'pendiente',
							'monto_pagado' => 0,
							'id_cuota_template' => null,
						];
						$unique = [
							'cod_pensum' => $pensumExact,
							'cod_inscrip' => $codInscrip,
							'id_costo_semestral' => $costo->id_costo_semestral,
							'numero_cuota' => $idx,
						];
						$model = AsignacionCostos::query()->where($unique)->first();
						if ($model) {
							$model->fill($vals);
							$model->save();
							$updated++;
						} else {
							$model = AsignacionCostos::create($unique + $vals);
							$created++;
							$rows[] = $model->id_asignacion_costo;
						}
						$filled++;
					}
				}

				Log::info('AsignacionCostos cuotas generadas (plantilla+fill)', [
					'cod_inscrip' => $codInscrip,
					'cod_pensum' => $pensumExact,
					'gestion' => $gestion,
					'cuotas' => ['created' => $created, 'updated' => $updated, 'rows' => $rows],
					'plantilla_pensum' => $plantillaPensum,
					'pensum_exact' => $pensumExact,
					'plantilla_ids_used' => $tplIds,
					'filled_missing' => $filled,
					'desired_total' => $desired,
					'id_costo_semestral' => $costo->id_costo_semestral,
					'tipo_costo' => $costo->tipo_costo,
					'matched_pensum' => $matchedPensum,
					'matched_tipo' => $matchedTipo,
				]);
				return;
			}
		}

		// 2) Fallback: generar cuotas por defaults usando monto mensual del costo_semestral
		$count = max(1, (int)($cuotasDefaults['count'] ?? 5));
		// Para ARRASTRE sin plantilla, generar exactamente 1 cuota
		if ($tipoIns === 'ARRASTRE') {
			$count = 1;
		}
		$firstDay = (int)($cuotasDefaults['first_due_day'] ?? 15);
		$intervalDays = (int)($cuotasDefaults['interval_days'] ?? 30);
		$useCalendarMonth = (bool)($cuotasDefaults['use_calendar_month'] ?? true);
		$baseDate = null;
		if ($ins && !empty($ins->fecha_inscripcion)) {
			$baseDate = Carbon::parse($ins->fecha_inscripcion);
		} else {
			$baseDate = Carbon::now();
		}

		$idx = 0;
		for ($i = 1; $i <= $count; $i++) {
			$idx = $i;
			if ($useCalendarMonth) {
				$due = (clone $baseDate)->startOfMonth()->addMonths($i-1)->day($firstDay);
				if ($due->lessThan($baseDate)) { $due->addMonth(); }
			} else {
				$due = (clone $baseDate)->addDays(($i-1) * max(1,$intervalDays));
			}
			$vals = [
				'monto' => ((float) $costo->monto_semestre) * $multiplier, // mensualidad (ajustada por arrastre si aplica)
				'estado' => true,
				'fecha_vencimiento' => $due->toDateString(),
				'estado_pago' => 'pendiente',
				'monto_pagado' => 0,
				'id_cuota_template' => null,
			];
			$unique = [
				'cod_pensum' => $pensumExact,
				'cod_inscrip' => (int) $codInscrip,
				'id_costo_semestral' => $costo->id_costo_semestral,
				'numero_cuota' => $idx,
			];
			$model = AsignacionCostos::query()->where($unique)->first();
			if ($model) {
				$model->fill($vals);
				$model->save();
				$updated++;
			} else {
				$model = AsignacionCostos::create($unique + $vals);
				$created++;
				$rows[] = $model->id_asignacion_costo;
			}
		}

		Log::info('AsignacionCostos cuotas generadas (defaults)', [
			'cod_inscrip' => $codInscrip,
			'cod_pensum' => $pensumExact,
			'gestion' => $gestion,
			'cuotas' => ['created' => $created, 'updated' => $updated, 'rows' => $rows],
			'count' => $count,
			'first_due_day' => $firstDay,
			'interval_days' => $intervalDays,
			'use_calendar_month' => $useCalendarMonth,
			'id_costo_semestral' => $costo->id_costo_semestral,
			'tipo_costo' => $costo->tipo_costo,
			'matched_pensum' => $matchedPensum,
			'matched_tipo' => $matchedTipo,
			'arrastre_count' => $materiasArrastre,
			'arrastre_payload_count' => $fallbackArrastreCount,
			'arrastre_multiplier' => $multiplier,
		]);
		return;
	}
}
