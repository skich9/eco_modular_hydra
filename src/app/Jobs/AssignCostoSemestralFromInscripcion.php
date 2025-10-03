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

		// Tomar cod_pensum desde la tabla inscripciones como fuente primaria
		$ins = Inscripcion::query()->find($codInscrip);
		if ($ins && !empty($ins->cod_pensum)) {
			$codPensum = $ins->cod_pensum; // override desde BD
		}

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

		if (!$turno || !$semestre) return;

		// Mapear tipo_inscripcion -> lista de tipo_costo (preferencias)
		$tipoCostoCandidates = $tipoCostoMap[$tipoIns]
			?: ($tipoCostoMap['NORMAL'] ?? ['Costo Mensual','Costo Semestral']);

		// A partir de ahora, usamos exclusivamente el cod_pensum de la inscripción
		$pensumExact = $codPensum;

		// Normalizar candidatos de tipo_costo a lower para match case-insensitive
		$tipoCostoCandidatesLower = array_map('strtolower', $tipoCostoCandidates);

		// Buscar costo semestral SOLO en el pensum de la inscripción
		$costo = null; $matchedPensum = null; $matchedTipo = null;
		foreach ($tipoCostoCandidatesLower as $tipoLower) {
			$costo = CostoSemestral::query()
				->where('cod_pensum', $pensumExact)
				->where('gestion', $gestion)
				->where('semestre', $semestre)
				->whereIn('turno', $turnoCandidates)
				->whereRaw('LOWER(tipo_costo) = ?', [$tipoLower])
				->first();
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
		if (!$costo) {
			Log::warning('No se encontró costo_semestral para inscripcion', [
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

		// Generación de cuotas en asignacion_costos (Opción C)
		$cuotasTipoMap = (array) config('hydra_assign.cuotas_tipo_map', []);
		$cuotasDefaults = (array) config('hydra_assign.cuotas_defaults', []);
		$tipoCostoLower = strtolower((string) $costo->tipo_costo);
		$cuotaTipo = $cuotasTipoMap[$tipoCostoLower] ?? null;

		// 1) Intentar plantilla de cuotas por contexto SOLO en el pensum de la inscripción
		$plantilla = collect();
		$plantillaPensum = null;
		Log::info('Cuotas plantilla: lookup params', [
			'cod_inscrip' => $codInscrip,
			'pensum_exact' => $pensumExact,
			'gestion' => $gestion,
			'semestre' => $semestre,
			'turno_candidates' => $turnoCandidates,
			'cuota_tipo' => $cuotaTipo,
		]);

		$qCuota = Cuota::query()
			->whereRaw('TRIM(gestion) = ?', [trim((string)$gestion)])
			->whereRaw('TRIM(cod_pensum) = ?', [$pensumExact])
			->where('semestre', (string)$semestre)
			->whereIn(DB::raw('UPPER(turno)'), array_map('strtoupper', $turnoCandidates));
		if (!empty($cuotaTipo)) {
			$qCuota->whereRaw('LOWER(tipo) = ?', [strtolower($cuotaTipo)]);
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
					'monto' => $tpl->monto, // plantilla manda
					'estado' => true,
					'fecha_vencimiento' => $tpl->fecha_vencimiento,
					'estado_pago' => 'pendiente',
					'monto_pagado' => 0,
					'id_cuota_template' => $tpl->id_cuota,
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
				$processed++;
			}
{{ ... }}
		Log::info('AsignacionCostos cuotas generadas (plantilla)', [
				Log::info('AsignacionCostos cuotas generadas (plantilla)', [
					'cod_inscrip' => $codInscrip,
					'cod_pensum' => $pensumExact,
					'gestion' => $gestion,
					'cuotas' => ['created' => $created, 'updated' => $updated, 'rows' => $rows],
					'plantilla_count' => $plantilla->count(),
					'plantilla_pensum' => $plantillaPensum,
					'pensum_exact' => $pensumExact,
					'plantilla_ids_used' => $tplIds,
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
				'monto' => $costo->monto_semestre, // mensualidad
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
		]);
		return;
	}
}
