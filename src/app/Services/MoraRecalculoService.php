<?php

namespace App\Services;

use App\Models\DatosMoraDetalle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoraRecalculoService
{
	private function debugEnabled()
	{
		try {
			return filter_var(env('MORA_BUSQUEDA_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function debugLog($msg, $ctx = [])
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

	/**
	 * Calcula el monto total acumulado de una mora, sumando recursivamente
	 * todas las moras vinculadas a través de id_mora_vinculada.
	 * IMPORTANTE: Resta monto_pagado y monto_descuento de cada mora para obtener el neto pendiente.
	 * Incluye moras en cualquier estado (PENDIENTE, CONGELADA_PRORROGA, PAUSADA_DUPLICIDAD, etc.)
	 */
	private function calcularMoraTotalAcumulado($mora)
	{
		try {
			$idMoraActual = isset($mora->id_asignacion_mora) ? (int)$mora->id_asignacion_mora : 0;

			// Calcular neto de la mora actual: monto_mora - descuento - pagado
			$montoMoraActual = (float)(isset($mora->monto_mora) ? $mora->monto_mora : 0);
			$descuentoActual = (float)(isset($mora->monto_descuento) ? $mora->monto_descuento : 0);
			$pagadoActual = (float)(isset($mora->monto_pagado) ? $mora->monto_pagado : 0);
			$total = max(0, $montoMoraActual - $descuentoActual - $pagadoActual);

			$this->debugLog('calcularMoraTotalAcumulado - mora actual', [
				'id_asignacion_mora' => $idMoraActual,
				'monto_mora' => $montoMoraActual,
				'descuento' => $descuentoActual,
				'pagado' => $pagadoActual,
				'neto_actual' => $total,
			]);

			$idMoraVinculada = isset($mora->id_mora_vinculada) ? (int)$mora->id_mora_vinculada : null;

			// Recursivamente sumar moras vinculadas (también restando sus descuentos y pagos)
			$visitados = [];
			while ($idMoraVinculada && !in_array($idMoraVinculada, $visitados)) {
				$visitados[] = $idMoraVinculada;

				$moraVinculada = DB::table('asignacion_mora')
					->where('id_asignacion_mora', $idMoraVinculada)
					->first(['monto_mora', 'monto_descuento', 'monto_pagado', 'id_mora_vinculada']);

				if ($moraVinculada) {
					$montoMoraVinc = (float)(isset($moraVinculada->monto_mora) ? $moraVinculada->monto_mora : 0);
					$descuentoVinc = (float)(isset($moraVinculada->monto_descuento) ? $moraVinculada->monto_descuento : 0);
					$pagadoVinc = (float)(isset($moraVinculada->monto_pagado) ? $moraVinculada->monto_pagado : 0);
					$netoVinc = max(0, $montoMoraVinc - $descuentoVinc - $pagadoVinc);

					$this->debugLog('calcularMoraTotalAcumulado - mora vinculada', [
						'id_mora_actual' => $idMoraActual,
						'id_mora_vinculada' => $idMoraVinculada,
						'monto_mora' => $montoMoraVinc,
						'descuento' => $descuentoVinc,
						'pagado' => $pagadoVinc,
						'neto_vinculada' => $netoVinc,
					]);

					$total += $netoVinc;
					$idMoraVinculada = isset($moraVinculada->id_mora_vinculada) ? (int)$moraVinculada->id_mora_vinculada : null;
				} else {
					break;
				}
			}

			$this->debugLog('calcularMoraTotalAcumulado - total final', [
				'id_asignacion_mora' => $idMoraActual,
				'total_acumulado' => $total,
			]);

			return $total;
		} catch (\Throwable $e) {
			$montoMora = (float)(isset($mora->monto_mora) ? $mora->monto_mora : 0);
			$descuento = (float)(isset($mora->monto_descuento) ? $mora->monto_descuento : 0);
			$pagado = (float)(isset($mora->monto_pagado) ? $mora->monto_pagado : 0);
			return max(0, $montoMora - $descuento - $pagado);
		}
	}

	public function syncMorasPorBusqueda($codCeta, $gestion, $hoy = null)
	{
		$hoy = $hoy ? Carbon::parse($hoy)->startOfDay() : Carbon::today();
		$this->debugLog('sync start', [
			'cod_ceta' => (int)$codCeta,
			'gestion' => (string)$gestion,
			'hoy' => $hoy->toDateString(),
		]);
		$this->crearMorasFaltantesPorBusqueda((int)$codCeta, (string)$gestion, $hoy);
		$moras = $this->obtenerMorasPendientesEstudiante((int)$codCeta, (string)$gestion);
		$this->debugLog('moras pendientes obtenidas', [
			'cod_ceta' => (int)$codCeta,
			'gestion' => (string)$gestion,
			'count' => is_array($moras) ? count($moras) : 0,
		]);
		return $this->recalcularMorasPendientesPorBusqueda($moras, $hoy);
	}

	public function recalcularMorasPendientesPorBusqueda($moras, $hoy = null)
	{
		$hoy = $hoy ? Carbon::parse($hoy)->startOfDay() : Carbon::today();
		$hoyStr = $hoy->toDateString();

		foreach ($moras as $i => $mora) {
			try {
				// Moras vienen como arrays desde obtenerMorasPendientesEstudiante
				$isArray = is_array($mora);
				$idAsignacionMora = (int)($isArray ? (isset($mora['id_asignacion_mora']) ? $mora['id_asignacion_mora'] : 0) : (isset($mora->id_asignacion_mora) ? $mora->id_asignacion_mora : 0));
				$idAsignacionCosto = (int)($isArray ? (isset($mora['id_asignacion_costo']) ? $mora['id_asignacion_costo'] : 0) : (isset($mora->id_asignacion_costo) ? $mora->id_asignacion_costo : 0));
				$codCeta = (int)($isArray ? (isset($mora['cod_ceta']) ? $mora['cod_ceta'] : 0) : (isset($mora->cod_ceta) ? $mora->cod_ceta : 0));
				if ($idAsignacionMora <= 0 || $idAsignacionCosto <= 0) {
					$this->debugLog('recalcular skip: ids invalidos', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
					]);
					continue;
				}fdslfdksaoisdhfsa

				$estado = $isArray ? strtoupper(trim((string)(isset($mora['estado']) ? $mora['estado'] : ''))) : (isset($mora->estado) ? strtoupper(trim((string)$mora->estado)) : '');
				if ($estado !== 'PENDIENTE') {
					$this->debugLog('recalcular skip: estado no recalculable', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'estado' => $estado,
					]);
					continue;
				}

				$prorrogaActivaQuery = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsignacionCosto)
					->where('activo', 1)
					->where('fecha_inicio_prorroga', '<=', $hoyStr)
					->where('fecha_fin_prorroga', '>=', $hoyStr);
				if ($codCeta > 0) {
					$prorrogaActivaQuery->where('cod_ceta', $codCeta);
				}
				$prorrogaActiva = $prorrogaActivaQuery->exists();

				if ($prorrogaActiva) {
					$prorrogaRow = null;
					try {
						$prorrogaRow = $prorrogaActivaQuery
							->orderBy('id_prorroga_mora', 'desc')
							->first(['id_prorroga_mora', 'cod_ceta', 'id_asignacion_costo', 'fecha_inicio_prorroga', 'fecha_fin_prorroga', 'activo']);
					} catch (\Throwable $e) {
						$prorrogaRow = null;
					}
					$this->debugLog('recalcular skip: prorroga activa', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'cod_ceta' => $codCeta,
						'hoy' => $hoyStr,
						'prorroga' => $prorrogaRow,
					]);
					continue;
				}

				$montoBaseDia = (float)($isArray ? (isset($mora['monto_base']) ? $mora['monto_base'] : 0) : (isset($mora->monto_base) ? $mora->monto_base : 0));
				$fechaInicioRaw = $isArray ? (isset($mora['fecha_inicio_mora']) ? $mora['fecha_inicio_mora'] : null) : (isset($mora->fecha_inicio_mora) ? $mora->fecha_inicio_mora : null);
				$fechaFinRaw = $isArray ? (isset($mora['fecha_fin_mora']) ? $mora['fecha_fin_mora'] : null) : (isset($mora->fecha_fin_mora) ? $mora->fecha_fin_mora : null);
				$fechaInicio = !empty($fechaInicioRaw) ? Carbon::parse($fechaInicioRaw)->startOfDay() : null;
				$fechaFin = !empty($fechaFinRaw) ? Carbon::parse($fechaFinRaw)->startOfDay() : null;

				if (!$fechaInicio || $montoBaseDia <= 0) {
					$this->debugLog('recalcular skip: sin fechaInicio o monto_base<=0', [
						'id_asignacion_mora' => $idAsignacionMora,
						'id_asignacion_costo' => $idAsignacionCosto,
						'fecha_inicio_mora' => $fechaInicioRaw,
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
				$montoActual = (float)($isArray ? (isset($mora['monto_mora']) ? $mora['monto_mora'] : 0) : (isset($mora->monto_mora) ? $mora->monto_mora : 0));

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

					if ($isArray) {
						$moras[$i]['monto_mora'] = $montoCalculado;
					} else {
						$moras[$i]->monto_mora = $montoCalculado;
					}
				}
			} catch (\Throwable $e) {
				continue;
			}
		}

		return $moras;
	}

	private function obtenerMorasPendientesEstudiante($codCeta, $gestion)
	{
		$insIds = DB::table('inscripciones')
			->where('cod_ceta', (int)$codCeta)
			->where('gestion', (string)$gestion)
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

		$moras = DB::table('asignacion_mora as am')
			->join('asignacion_costos as ac', 'am.id_asignacion_costo', '=', 'ac.id_asignacion_costo')
			->join('inscripciones as i', 'ac.cod_inscrip', '=', 'i.cod_inscrip')
			->whereIn('am.id_asignacion_costo', $asignacionIds)
			->whereIn('am.estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
			->select(
				'am.id_asignacion_mora',
				'am.id_asignacion_costo',
				'am.id_asignacion_vinculada',
				'am.id_mora_vinculada',
				'am.fecha_inicio_mora',
				'am.fecha_fin_mora',
				'am.monto_base',
				'am.monto_mora',
				'am.monto_descuento',
				'am.monto_pagado',
				'am.estado',
				'am.observaciones',
				'i.cod_ceta',
				'ac.numero_cuota',
				'ac.id_cuota_template'
			)
			->orderBy('ac.numero_cuota', 'asc')
			->get()
			->toArray();

		// Calcular monto_mora_total acumulado para cada mora (sumando moras vinculadas)
		$hoy = Carbon::today();
		$morasConTotal = [];
		foreach ($moras as $mora) {
			$moraArray = (array)$mora;
			$moraArray['monto_mora_total'] = $this->calcularMoraTotalAcumulado($mora);

			// Calcular dias_mora: desde fecha_inicio_mora hasta hoy
			$diasMora = 0;
			if (!empty($mora->fecha_inicio_mora)) {
				try {
					$fechaInicio = Carbon::parse($mora->fecha_inicio_mora)->startOfDay();
					$diasMora = $fechaInicio->diffInDays($hoy) + 1; // +1 para incluir el día de inicio
				} catch (\Throwable $e) {
					$diasMora = 0;
				}
			}
			$moraArray['dias_mora'] = $diasMora;

			$morasConTotal[] = $moraArray;

			$this->debugLog('mora con total calculado', [
				'id_asignacion_mora' => isset($mora->id_asignacion_mora) ? (int)$mora->id_asignacion_mora : 0,
				'monto_mora' => isset($mora->monto_mora) ? (float)$mora->monto_mora : 0,
				'monto_mora_total' => $moraArray['monto_mora_total'],
				'dias_mora' => $diasMora,
				'id_mora_vinculada' => isset($mora->id_mora_vinculada) ? (int)$mora->id_mora_vinculada : null,
			]);
		}

		$this->debugLog('moras retornadas con totales', [
			'count' => count($morasConTotal),
		]);

		return $morasConTotal;
	}

	private function crearMorasFaltantesPorBusqueda($codCeta, $gestion, $hoy)
	{
		$this->debugLog('crearMorasFaltantes start', [
			'cod_ceta' => (int)$codCeta,
			'gestion' => (string)$gestion,
			'hoy' => $hoy->toDateString(),
		]);
		$hoyStr = $hoy->toDateString();
		try {
			$prorrogasActivas = DB::table('prorrogas_mora')
				->where('cod_ceta', $codCeta)
				->where('activo', 1)
				->where('fecha_inicio_prorroga', '<=', $hoyStr)
				->where('fecha_fin_prorroga', '>=', $hoyStr)
				->orderBy('id_asignacion_costo', 'asc')
				->get(['id_asignacion_costo', 'fecha_inicio_prorroga', 'fecha_fin_prorroga'])
				->toArray();
			$this->debugLog('prorrogas activas (cod_ceta)', [
				'cod_ceta' => $codCeta,
				'hoy' => $hoyStr,
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
				'i.cod_curso',
				'i.tipo_inscripcion'
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

		$idsAsign = array_values(array_unique(array_map(function($r){ return (int)(isset($r->id_asignacion_costo) ? $r->id_asignacion_costo : 0); }, $cuotas)));
		$existentes = DB::table('asignacion_mora')
			->whereIn('id_asignacion_costo', $idsAsign)
			->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
			->pluck('id_asignacion_costo')
			->map(function($v){ return (int)$v; })
			->toArray();
		$existentesMap = array_fill_keys($existentes, true);

		$porGrupo = [];
		foreach ($cuotas as $c) {
			$cuotaN = (int)(isset($c->numero_cuota) ? $c->numero_cuota : 0);
			if ($cuotaN <= 0) {
				continue;
			}
			$k = (string)$codCeta . '|' . (string)$gestion . '|' . (string)$cuotaN;
			if (!isset($porGrupo[$k])) {
				$porGrupo[$k] = [];
			}
			$porGrupo[$k][] = (int)(isset($c->id_asignacion_costo) ? $c->id_asignacion_costo : 0);
		}
		foreach ($porGrupo as $k => $ids) {
			$porGrupo[$k] = array_values(array_unique(array_filter($ids)));
		}

		$gruposPausados = [];

		foreach ($cuotas as $c) {
			try {
				$idAsign = (int)(isset($c->id_asignacion_costo) ? $c->id_asignacion_costo : 0);
				if ($idAsign <= 0) {
					$this->debugLog('crearMorasFaltantes skip: id_asignacion_costo invalido', [
						'id_asignacion_costo' => $idAsign,
					]);
					continue;
				}

				$cuotaN = (int)(isset($c->numero_cuota) ? $c->numero_cuota : 0);
				if ($cuotaN <= 0) {
					continue;
				}

				$grupoKey = (string)$codCeta . '|' . (string)$gestion . '|' . (string)$cuotaN;
				$idsGrupo = isset($porGrupo[$grupoKey]) ? $porGrupo[$grupoKey] : [];
				$esDuplicado = count($idsGrupo) > 1;

				$tipoIns = isset($c->tipo_inscripcion) ? strtoupper(trim((string)$c->tipo_inscripcion)) : '';
				$esNormal = $tipoIns === 'NORMAL';
				if ($esDuplicado && !isset($gruposPausados[$grupoKey]) && !empty($idsGrupo)) {
					DB::table('asignacion_mora as am')
						->join('asignacion_costos as ac', 'am.id_asignacion_costo', '=', 'ac.id_asignacion_costo')
						->join('inscripciones as i', 'ac.cod_inscrip', '=', 'i.cod_inscrip')
						->whereIn('am.id_asignacion_costo', $idsGrupo)
						->where('am.estado', 'PENDIENTE')
						->where('i.tipo_inscripcion', '!=', 'NORMAL')
						->update([
							'am.estado' => 'PAUSADA_DUPLICIDAD',
							'am.updated_at' => now(),
						]);
					$gruposPausados[$grupoKey] = true;
				}

				$prorrogaActiva = DB::table('prorrogas_mora')
					->where('cod_ceta', $codCeta)
					->where('id_asignacion_costo', $idAsign)
					->where('activo', 1)
					->where('fecha_inicio_prorroga', '<=', $hoyStr)
					->where('fecha_fin_prorroga', '>=', $hoyStr)
					->first(['id_prorroga_mora', 'cod_ceta', 'id_asignacion_costo', 'fecha_inicio_prorroga', 'fecha_fin_prorroga', 'activo']);

				$prorrogaTerminada = DB::table('prorrogas_mora')
					->where('cod_ceta', $codCeta)
					->where('id_asignacion_costo', $idAsign)
					->where('fecha_fin_prorroga', '<', $hoyStr)
					->orderBy('fecha_fin_prorroga', 'desc')
					->first(['fecha_fin_prorroga']);
				$inicioPosterior = null;
				if ($prorrogaTerminada && !empty($prorrogaTerminada->fecha_fin_prorroga)) {
					$inicioPosterior = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga)->startOfDay()->addDay();
				}

				// Si hay prórroga activa Y hay prórroga terminada, verificar si hay gap para crear mora entre prórrogas
				if ($prorrogaActiva && $prorrogaTerminada) {
					$fechaFinAnterior = Carbon::parse($prorrogaTerminada->fecha_fin_prorroga)->startOfDay();
					$fechaInicioActiva = Carbon::parse($prorrogaActiva->fecha_inicio_prorroga)->startOfDay();
					$fechaInicioGap = $fechaFinAnterior->copy()->addDay();
					$fechaFinGap = $fechaInicioActiva->copy()->subDay();

					if ($fechaInicioGap->lte($fechaFinGap)) {
						// Hay gap entre prórrogas, verificar si ya existe mora en ese rango
						$moraEnGap = DB::table('asignacion_mora')
							->where('id_asignacion_costo', $idAsign)
							->where('fecha_inicio_mora', '>=', $fechaInicioGap->toDateString())
							->where('fecha_inicio_mora', '<=', $fechaFinGap->toDateString())
							->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA'])
							->exists();

						if (!$moraEnGap) {
							$this->debugLog('crearMorasFaltantes: creando mora entre prorrogas (gap)', [
								'id_asignacion_costo' => $idAsign,
								'fecha_inicio_gap' => $fechaInicioGap->toDateString(),
								'fecha_fin_gap' => $fechaFinGap->toDateString(),
							]);
							// Continuar con la lógica normal de creación (no skip)
						} else {
							$this->debugLog('crearMorasFaltantes skip: ya existe mora en gap entre prorrogas', [
								'id_asignacion_costo' => $idAsign,
								'fecha_inicio_gap' => $fechaInicioGap->toDateString(),
							]);
							continue;
						}
					} else {
						// No hay gap (prórrogas consecutivas), skip
						$this->debugLog('crearMorasFaltantes skip: prorroga activa sin gap', [
							'id_asignacion_costo' => $idAsign,
							'hoy' => $hoy->toDateString(),
						]);
						continue;
					}
				}

				// Si hay prórroga activa sin prórroga terminada, skip
				if ($prorrogaActiva) {
					$this->debugLog('crearMorasFaltantes skip: prorroga activa', [
						'id_asignacion_costo' => $idAsign,
						'cod_ceta' => (int)$codCeta,
						'hoy' => $hoyStr,
						'prorroga' => $prorrogaActiva,
					]);
					continue;
				}

				if (isset($existentesMap[$idAsign]) && !$inicioPosterior) {
					$this->debugLog('crearMorasFaltantes skip: ya existe mora pendiente/pausada', [
						'id_asignacion_costo' => $idAsign,
					]);
					continue;
				}

				if (isset($existentesMap[$idAsign]) && $inicioPosterior) {
					$yaTieneMoraPost = DB::table('asignacion_mora')
						->where('id_asignacion_costo', $idAsign)
						->where('fecha_inicio_mora', '>=', $inicioPosterior->toDateString())
						->whereIn('estado', ['PENDIENTE', 'CONGELADA_PRORROGA', 'PAUSADA_DUPLICIDAD', 'CERRADA_SIN_CUOTA', 'EN_ESPERA', 'PAGADO', 'CONDONADO'])
						->exists();
					if ($yaTieneMoraPost) {
						$this->debugLog('crearMorasFaltantes skip: ya existe mora post-prorroga', [
							'id_asignacion_costo' => $idAsign,
							'inicio_posterior' => $inicioPosterior->toDateString(),
						]);
						continue;
					}
				}

				$codPensum = (string)(isset($c->cod_pensum) ? $c->cod_pensum : '');
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
					'id_datos_mora_detalle' => (int)(isset($configuracion->id_datos_mora_detalle) ? $configuracion->id_datos_mora_detalle : 0),
					'cuota' => $cuotaN,
					'cod_pensum' => isset($configuracion->cod_pensum) ? $configuracion->cod_pensum : null,
					'semestre' => isset($configuracion->semestre) ? $configuracion->semestre : null,
					'monto' => isset($configuracion->monto) ? $configuracion->monto : null,
					'fecha_inicio' => isset($configuracion->fecha_inicio) ? $configuracion->fecha_inicio : null,
					'fecha_fin' => isset($configuracion->fecha_fin) ? $configuracion->fecha_fin : null,
				]);

				$fechaInicioCfg = !empty($configuracion->fecha_inicio) ? Carbon::parse($configuracion->fecha_inicio)->startOfDay() : null;
				$fechaFinCfg = !empty($configuracion->fecha_fin) ? Carbon::parse($configuracion->fecha_fin)->startOfDay() : null;
				if (!$fechaInicioCfg) {
					$this->debugLog('crearMorasFaltantes skip: configuracion sin fecha_inicio', [
						'id_asignacion_costo' => $idAsign,
						'id_datos_mora_detalle' => (int)(isset($configuracion->id_datos_mora_detalle) ? $configuracion->id_datos_mora_detalle : 0),
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

				if ($inicioPosterior && $inicioPosterior->gt($fechaInicioCfg)) {
					$fechaInicioCfg = $inicioPosterior;
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
				$montoBaseDia = (float)(isset($configuracion->monto) ? $configuracion->monto : 0);
				$montoMora = (float)$montoBaseDia * (int)$dias;

				$estadoInicial = ($esDuplicado && !$esNormal) ? 'PAUSADA_DUPLICIDAD' : 'PENDIENTE';
				$observ = 'Mora aplicada automáticamente desde ' . $fechaInicioCfg->format('Y-m-d');
				if ($esDuplicado && !$esNormal) {
					$observ .= ' | PAUSADA_DUPLICIDAD por inscripción duplicada';
				}

				$idMoraVinculada = null;
				if ($inicioPosterior) {
					$moraAnterior = DB::table('asignacion_mora')
						->where('id_asignacion_costo', $idAsign)
						->where('fecha_inicio_mora', '<', $fechaInicioCfg->toDateString())
						->orderBy('id_asignacion_mora', 'desc')
						->first(['id_asignacion_mora']);
					if ($moraAnterior && isset($moraAnterior->id_asignacion_mora)) {
						$idMoraVinculada = (int)$moraAnterior->id_asignacion_mora;
					}
				}

				DB::table('asignacion_mora')->insert([
					'id_asignacion_costo' => $idAsign,
					'id_mora_vinculada' => $idMoraVinculada,
					'id_datos_mora_detalle' => (int)(isset($configuracion->id_datos_mora_detalle) ? $configuracion->id_datos_mora_detalle : 0),
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
					'id_mora_vinculada' => $idMoraVinculada,
					'id_datos_mora_detalle' => (int)(isset($configuracion->id_datos_mora_detalle) ? $configuracion->id_datos_mora_detalle : 0),
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
