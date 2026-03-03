<?php

namespace App\Services;

use App\Models\DatosMoraDetalle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoraRecalculoService
{
	private function debugEnabled(): bool
	{
		try {
			return filter_var(env('MORA_BUSQUEDA_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function debugLog(string $msg, array $ctx = []): void
	{
		if (!$this->debugEnabled()) {
			return;
		}
		try {
			Log::info('[MORA_BUSQUEDA] ' . $msg, $ctx);
		} catch (\Throwable $e) {
			return;
		}
	}

	public function syncMorasPorBusqueda(int $codCeta, string $gestion, $hoy = null)
	{
		$hoy = $hoy ? Carbon::parse($hoy)->startOfDay() : Carbon::today();
		$this->debugLog('sync start', [
			'cod_ceta' => $codCeta,
			'gestion' => $gestion,
			'hoy' => $hoy->toDateString(),
		]);
		$this->crearMorasFaltantesPorBusqueda($codCeta, $gestion, $hoy);
		$moras = $this->obtenerMorasPendientesEstudiante($codCeta, $gestion);
		$this->debugLog('moras pendientes obtenidas', [
			'cod_ceta' => $codCeta,
			'gestion' => $gestion,
			'count' => is_array($moras) ? count($moras) : 0,
		]);
		return $this->recalcularMorasPendientesPorBusqueda($moras, $hoy);
	}

	public function recalcularMorasPendientesPorBusqueda(array $moras, $hoy = null)
	{
		$hoy = $hoy ? Carbon::parse($hoy)->startOfDay() : Carbon::today();

		foreach ($moras as $i => $mora) {
			try {
				$idAsignacionMora = (int)($mora->id_asignacion_mora ?? 0);
				$idAsignacionCosto = (int)($mora->id_asignacion_costo ?? 0);
				if ($idAsignacionMora <= 0 || $idAsignacionCosto <= 0) {
					$this->debugLog('recalcular skip: ids invalidos', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
					]);
					continue;
				}

				$prorrogaActiva = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsignacionCosto)
					->where('activo', true)
					->where('fecha_inicio_prorroga', '<=', $hoy)
					->where('fecha_fin_prorroga', '>=', $hoy)
					->exists();

				if ($prorrogaActiva) {
					$this->debugLog('recalcular skip: prorroga activa', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
					]);
					continue;
				}

				$montoBaseDia = (float)($mora->monto_base ?? 0);
				$fechaInicio = !empty($mora->fecha_inicio_mora) ? Carbon::parse($mora->fecha_inicio_mora)->startOfDay() : null;
				$fechaFin = !empty($mora->fecha_fin_mora) ? Carbon::parse($mora->fecha_fin_mora)->startOfDay() : null;

				if (!$fechaInicio || $montoBaseDia <= 0) {
					$this->debugLog('recalcular skip: sin fechaInicio o monto_base<=0', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'fecha_inicio_mora' => $mora->fecha_inicio_mora ?? null,
						'monto_base' => $montoBaseDia,
					]);
					continue;
				}

				if ($fechaInicio->gt($hoy)) {
					$this->debugLog('recalcular skip: fechaInicio>hoy', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'fecha_inicio_mora' => $fechaInicio->toDateString(),
						'hoy' => $hoy->toDateString(),
					]);
					continue;
				}

				$fechaCalculo = $fechaFin ? ($hoy->lt($fechaFin) ? $hoy : $fechaFin) : $hoy;
				if ($fechaCalculo->lt($fechaInicio)) {
					$this->debugLog('recalcular skip: fechaCalculo<fechaInicio', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'fecha_inicio_mora' => $fechaInicio->toDateString(),
						'fecha_calculo' => $fechaCalculo->toDateString(),
					]);
					continue;
				}

				$dias = $fechaInicio->diffInDays($fechaCalculo) + 1;
				$montoCalculado = (float)$montoBaseDia * (int)$dias;
				$montoActual = (float)($mora->monto_mora ?? 0);

				if ($montoCalculado > ($montoActual + 0.0001)) {
					$this->debugLog('recalcular update monto_mora', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'monto_actual' => $montoActual,
						'monto_calculado' => $montoCalculado,
						'dias' => $dias,
					]);
					DB::table('asignacion_mora')
						->where('id_asignacion_mora', $idAsignacionMora)
						->update([
							'monto_mora' => $montoCalculado,
							'updated_at' => now(),
						]);

					$moras[$i]->monto_mora = $montoCalculado;
				}
			} catch (\Throwable $e) {
				continue;
			}
		}

		return $moras;
	}

	private function obtenerMorasPendientesEstudiante(int $codCeta, string $gestion)
	{
		$insIds = DB::table('inscripciones')
			->where('cod_ceta', $codCeta)
			->where('gestion', $gestion)
			->pluck('cod_inscrip')
			->toArray();

		if (empty($insIds)) {
			return [];
		}

		$asignacionIds = DB::table('asignacion_costos')
			->whereIn('cod_inscrip', $insIds)
			->pluck('id_asignacion_costo')
			->toArray();

		if (empty($asignacionIds)) {
			return [];
		}

		return DB::table('asignacion_mora as am')
			->join('asignacion_costos as ac', 'am.id_asignacion_costo', '=', 'ac.id_asignacion_costo')
			->whereIn('am.id_asignacion_costo', $asignacionIds)
			->whereIn('am.estado', ['PENDIENTE', 'EN_ESPERA'])
			->select(
				'am.id_asignacion_mora',
				'am.id_asignacion_costo',
				'am.id_asignacion_vinculada',
				'am.fecha_inicio_mora',
				'am.fecha_fin_mora',
				'am.monto_base',
				'am.monto_mora',
				'am.monto_descuento',
				'am.monto_pagado',
				'am.estado',
				'am.observaciones',
				'ac.numero_cuota',
				'ac.id_cuota_template'
			)
			->orderBy('ac.numero_cuota', 'asc')
			->get()
			->toArray();
	}

	private function crearMorasFaltantesPorBusqueda(int $codCeta, string $gestion, Carbon $hoy)
	{
		$this->debugLog('crearMorasFaltantes start', [
			'cod_ceta' => $codCeta,
			'gestion' => $gestion,
			'hoy' => $hoy->toDateString(),
		]);
		try {
			$prorrogasActivas = DB::table('prorrogas_mora')
				->where('cod_ceta', $codCeta)
				->where('activo', true)
				->where('fecha_inicio_prorroga', '<=', $hoy)
				->where('fecha_fin_prorroga', '>=', $hoy)
				->orderBy('id_asignacion_costo', 'asc')
				->get(['id_asignacion_costo', 'fecha_inicio_prorroga', 'fecha_fin_prorroga'])
				->toArray();
			$this->debugLog('prorrogas activas (cod_ceta)', [
				'cod_ceta' => $codCeta,
				'hoy' => $hoy->toDateString(),
				'count' => is_array($prorrogasActivas) ? count($prorrogasActivas) : 0,
				'items' => $prorrogasActivas,
			]);
		} catch (\Throwable $e) {
			// no bloquear
		}
		$cuotas = DB::table('asignacion_costos as ac')
			->join('inscripciones as i', 'ac.cod_inscrip', '=', 'i.cod_inscrip')
			->where('i.cod_ceta', $codCeta)
			->where('i.gestion', $gestion)
			->whereIn('ac.estado_pago', ['PENDIENTE', 'PARCIAL', 'pendiente', 'parcial'])
			->select(
				'ac.id_asignacion_costo',
				'ac.cod_pensum',
				'ac.numero_cuota',
				'i.cod_ceta',
				'i.gestion',
				'i.cod_curso'
			)
			->get()
			->toArray();

		if (empty($cuotas)) {
			$this->debugLog('crearMorasFaltantes: sin cuotas pendientes/parciales', [
				'cod_ceta' => $codCeta,
				'gestion' => $gestion,
			]);
			return;
		}
		$this->debugLog('cuotas candidatas', [
			'cod_ceta' => $codCeta,
			'gestion' => $gestion,
			'count' => count($cuotas),
		]);

		$idsAsign = array_values(array_unique(array_map(function($r){ return (int)($r->id_asignacion_costo ?? 0); }, $cuotas)));
		$existentes = DB::table('asignacion_mora')
			->whereIn('id_asignacion_costo', $idsAsign)
			->whereIn('estado', ['PENDIENTE', 'EN_ESPERA'])
			->pluck('id_asignacion_costo')
			->map(function($v){ return (int)$v; })
			->toArray();
		$existentesMap = array_fill_keys($existentes, true);

		$porGrupo = [];
		foreach ($cuotas as $c) {
			$cuotaN = (int)($c->numero_cuota ?? 0);
			if ($cuotaN <= 0) {
				continue;
			}
			$k = (string)$codCeta . '|' . (string)$gestion . '|' . (string)$cuotaN;
			if (!isset($porGrupo[$k])) {
				$porGrupo[$k] = [];
			}
			$porGrupo[$k][] = (int)($c->id_asignacion_costo ?? 0);
		}
		foreach ($porGrupo as $k => $ids) {
			$porGrupo[$k] = array_values(array_unique(array_filter($ids)));
		}

		$gruposPausados = [];

		foreach ($cuotas as $c) {
			try {
				$idAsign = (int)($c->id_asignacion_costo ?? 0);
				if ($idAsign <= 0) {
					$this->debugLog('crearMorasFaltantes skip: id_asignacion_costo invalido', [
						'id_asignacion_costo' => $idAsign,
					]);
					continue;
				}

				$cuotaN = (int)($c->numero_cuota ?? 0);
				if ($cuotaN <= 0) {
					continue;
				}

				$grupoKey = (string)$codCeta . '|' . (string)$gestion . '|' . (string)$cuotaN;
				$idsGrupo = isset($porGrupo[$grupoKey]) ? $porGrupo[$grupoKey] : [];
				$esDuplicado = count($idsGrupo) > 1;

				if ($esDuplicado && !isset($gruposPausados[$grupoKey]) && !empty($idsGrupo)) {
					DB::table('asignacion_mora')
						->whereIn('id_asignacion_costo', $idsGrupo)
						->where('estado', 'PENDIENTE')
						->update([
							'estado' => 'EN_ESPERA',
							'updated_at' => now(),
						]);
					$gruposPausados[$grupoKey] = true;
				}

				if (isset($existentesMap[$idAsign])) {
					$this->debugLog('crearMorasFaltantes skip: ya existe mora pendiente/en_espera', [
						'id_asignacion_costo' => $idAsign,
					]);
					continue;
				}

				$prorrogaActiva = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsign)
					->where('activo', true)
					->where('fecha_inicio_prorroga', '<=', $hoy)
					->where('fecha_fin_prorroga', '>=', $hoy)
					->exists();
				if ($prorrogaActiva) {
					$this->debugLog('crearMorasFaltantes skip: prorroga activa', [
						'id_asignacion_costo' => $idAsign,
						'hoy' => $hoy->toDateString(),
					]);
					continue;
				}

				$codPensum = (string)($c->cod_pensum ?? '');
				$codPensumNorm = $this->normalizarCodPensum($codPensum);
				$pensumsBusqueda = [$codPensum];
				if ($codPensumNorm !== '' && $codPensumNorm !== $codPensum) {
					$pensumsBusqueda[] = $codPensumNorm;
				}
				$pensumsBusqueda = array_values(array_unique(array_filter($pensumsBusqueda)));

				$semestre = $this->obtenerSemestreInscripcionDeRow($c);
				$this->debugLog('buscar configuracion', [
					'id_asignacion_costo' => $idAsign,
					'cuota' => $cuotaN,
					'pensum' => $codPensum,
					'pensum_norm' => $codPensumNorm,
					'pensums_busqueda' => $pensumsBusqueda,
					'semestre' => $semestre,
					'gestion' => $gestion,
					'hoy' => $hoy->toDateString(),
				]);
				$queryCfg = DatosMoraDetalle::query()
					->vigente($hoy)
					->where('cuota', $cuotaN)
					->where('activo', true)
					->where(function($q) use ($pensumsBusqueda) {
						$q->whereIn('cod_pensum', $pensumsBusqueda)
							->orWhereNull('cod_pensum');
					})
					->whereHas('datosMora', function($q) use ($gestion) {
						$q->where('gestion', $gestion);
					});

				if (!empty($semestre)) {
					$queryCfg->where('semestre', $semestre);
				}

				$configuracion = $queryCfg->with('datosMora')
					->orderBy('semestre', 'asc')
					->orderBy('id_datos_mora_detalle', 'desc')
					->first();
				if (!$configuracion) {
					$this->debugLog('crearMorasFaltantes skip: sin configuracion DatosMoraDetalle', [
						'id_asignacion_costo' => $idAsign,
						'cuota' => $cuotaN,
						'pensum' => $codPensum,
						'semestre' => $semestre,
						'gestion' => $gestion,
					]);
					continue;
				}
				$this->debugLog('configuracion encontrada', [
					'id_asignacion_costo' => $idAsign,
					'id_datos_mora_detalle' => (int)($configuracion->id_datos_mora_detalle ?? 0),
					'cuota' => $cuotaN,
					'cod_pensum' => $configuracion->cod_pensum ?? null,
					'semestre' => $configuracion->semestre ?? null,
					'monto' => $configuracion->monto ?? null,
					'fecha_inicio' => $configuracion->fecha_inicio ?? null,
					'fecha_fin' => $configuracion->fecha_fin ?? null,
				]);

				$fechaInicioCfg = !empty($configuracion->fecha_inicio) ? Carbon::parse($configuracion->fecha_inicio)->startOfDay() : null;
				$fechaFinCfg = !empty($configuracion->fecha_fin) ? Carbon::parse($configuracion->fecha_fin)->startOfDay() : null;
				if (!$fechaInicioCfg) {
					$this->debugLog('crearMorasFaltantes skip: configuracion sin fecha_inicio', [
						'id_asignacion_costo' => $idAsign,
						'id_datos_mora_detalle' => (int)($configuracion->id_datos_mora_detalle ?? 0),
					]);
					continue;
				}
				if ($fechaInicioCfg->gt($hoy)) {
					$this->debugLog('crearMorasFaltantes skip: fecha_inicio cfg > hoy', [
						'id_asignacion_costo' => $idAsign,
						'fecha_inicio_cfg' => $fechaInicioCfg->toDateString(),
						'hoy' => $hoy->toDateString(),
					]);
					continue;
				}

				$prorrogaTerminada = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsign)
					->where('fecha_fin_prorroga', '<', $hoy)
					->orderBy('fecha_fin_prorroga', 'desc')
					->first(['fecha_fin_prorroga']);
				if ($prorrogaTerminada && !empty($prorrogaTerminada->fecha_fin_prorroga)) {
					$inicioPosterior = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga)->startOfDay()->addDay();
					if ($inicioPosterior->gt($fechaInicioCfg)) {
						$fechaInicioCfg = $inicioPosterior;
					}
				}
				if ($fechaInicioCfg->gt($hoy)) {
					$this->debugLog('crearMorasFaltantes skip: inicio posterior a hoy (post-prorroga)', [
						'id_asignacion_costo' => $idAsign,
						'fecha_inicio_cfg' => $fechaInicioCfg->toDateString(),
						'hoy' => $hoy->toDateString(),
					]);
					continue;
				}

				$fechaCalculo = $fechaFinCfg ? ($hoy->lt($fechaFinCfg) ? $hoy : $fechaFinCfg) : $hoy;
				if ($fechaCalculo->lt($fechaInicioCfg)) {
					$this->debugLog('crearMorasFaltantes skip: fechaCalculo<fechaInicioCfg', [
						'id_asignacion_costo' => $idAsign,
						'fecha_inicio_cfg' => $fechaInicioCfg->toDateString(),
						'fecha_calculo' => $fechaCalculo->toDateString(),
					]);
					continue;
				}

				$dias = $fechaInicioCfg->diffInDays($fechaCalculo) + 1;
				$montoBaseDia = (float)($configuracion->monto ?? 0);
				$montoMora = (float)$montoBaseDia * (int)$dias;

				$estadoInicial = $esDuplicado ? 'EN_ESPERA' : 'PENDIENTE';
				$observ = 'Mora aplicada automáticamente desde ' . $fechaInicioCfg->format('Y-m-d');
				if ($esDuplicado) {
					$observ .= ' | EN_ESPERA por inscripción duplicada';
				}

				DB::table('asignacion_mora')->insert([
					'id_asignacion_costo' => $idAsign,
					'id_datos_mora_detalle' => (int)($configuracion->id_datos_mora_detalle ?? 0),
					'fecha_inicio_mora' => $fechaInicioCfg->toDateString(),
					'fecha_fin_mora' => $fechaFinCfg ? $fechaFinCfg->toDateString() : null,
					'monto_base' => $montoBaseDia,
					'monto_mora' => $montoMora,
					'monto_descuento' => 0,
					'estado' => $estadoInicial,
					'observaciones' => $observ,
					'created_at' => now(),
					'updated_at' => now(),
				]);

				$this->debugLog('crearMorasFaltantes insert ok', [
					'id_asignacion_costo' => $idAsign,
					'id_datos_mora_detalle' => (int)($configuracion->id_datos_mora_detalle ?? 0),
					'fecha_inicio_mora' => $fechaInicioCfg->toDateString(),
					'fecha_fin_mora' => $fechaFinCfg ? $fechaFinCfg->toDateString() : null,
					'monto_base' => $montoBaseDia,
					'monto_mora' => $montoMora,
					'estado' => $estadoInicial,
					'dias' => $dias,
				]);

				$existentesMap[$idAsign] = true;
			} catch (\Throwable $e) {
				continue;
			}
		}
	}

	private function normalizarCodPensum($codPensum)
	{
		$valor = strtoupper(trim((string)$codPensum));
		if ($valor === '') {
			return '';
		}

		if (preg_match('/^\d+-(.+)$/', $valor, $m)) {
			return trim((string)$m[1]);
		}

		return $valor;
	}

	private function obtenerSemestreInscripcionDeRow($row)
	{
		if (isset($row->semestre) && $row->semestre !== null && $row->semestre !== '') {
			return (string)((int)$row->semestre);
		}
		if (isset($row->nro_semestre) && $row->nro_semestre !== null && $row->nro_semestre !== '') {
			return (string)((int)$row->nro_semestre);
		}
		if (isset($row->gestion) && is_string($row->gestion)) {
			$g = trim((string)$row->gestion);
			if (preg_match('/^\s*(\d+)\s*\//', $g, $m)) {
				return (string)((int)$m[1]);
			}
			if (preg_match('/-(\d+)/', $g, $m)) {
				return (string)((int)$m[1]);
			}
		}
		if (isset($row->cod_curso) && $row->cod_curso) {
			$cc = (string)$row->cod_curso;
			if (preg_match('/(\d+)/', $cc, $m)) {
				$n = (int)$m[1];
				if ($n > 0) {
					return (string)$n;
				}
			}
		}
		return null;
	}
}
