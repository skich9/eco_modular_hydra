<?php

namespace App\Services;

use App\Models\DatosMoraDetalle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MoraRecalculoService
{
	public function syncMorasPorBusqueda(int $codCeta, string $gestion, $hoy = null)
	{
		$hoy = $hoy ? Carbon::parse($hoy)->startOfDay() : Carbon::today();
		$this->crearMorasFaltantesPorBusqueda($codCeta, $gestion, $hoy);
		$moras = $this->obtenerMorasPendientesEstudiante($codCeta, $gestion);
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
					continue;
				}

				$prorrogaActiva = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsignacionCosto)
					->where('activo', true)
					->where('fecha_inicio_prorroga', '<=', $hoy)
					->where('fecha_fin_prorroga', '>=', $hoy)
					->exists();

				if ($prorrogaActiva) {
					continue;
				}

				$montoBaseDia = (float)($mora->monto_base ?? 0);
				$fechaInicio = !empty($mora->fecha_inicio_mora) ? Carbon::parse($mora->fecha_inicio_mora)->startOfDay() : null;
				$fechaFin = !empty($mora->fecha_fin_mora) ? Carbon::parse($mora->fecha_fin_mora)->startOfDay() : null;

				if (!$fechaInicio || $montoBaseDia <= 0) {
					continue;
				}

				if ($fechaInicio->gt($hoy)) {
					continue;
				}

				$fechaCalculo = $fechaFin ? ($hoy->lt($fechaFin) ? $hoy : $fechaFin) : $hoy;
				if ($fechaCalculo->lt($fechaInicio)) {
					continue;
				}

				$dias = $fechaInicio->diffInDays($fechaCalculo) + 1;
				$montoCalculado = (float)$montoBaseDia * (int)$dias;
				$montoActual = (float)($mora->monto_mora ?? 0);

				if ($montoCalculado > ($montoActual + 0.0001)) {
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
				'i.semestre',
				'i.nro_semestre',
				'i.cod_curso'
			)
			->get()
			->toArray();

		if (empty($cuotas)) {
			return;
		}

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
					continue;
				}

				$prorrogaActiva = DB::table('prorrogas_mora')
					->where('id_asignacion_costo', $idAsign)
					->where('activo', true)
					->where('fecha_inicio_prorroga', '<=', $hoy)
					->where('fecha_fin_prorroga', '>=', $hoy)
					->exists();
				if ($prorrogaActiva) {
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
				$queryCfg = DatosMoraDetalle::whereIn('cod_pensum', $pensumsBusqueda)
					->where('cuota', $cuotaN)
					->where('activo', true)
					->whereHas('datosMora', function($q) use ($gestion) {
						$q->where('gestion', $gestion);
					});
				if (!empty($semestre)) {
					$queryCfg->where('semestre', $semestre);
				}

				$configuracion = $queryCfg->with('datosMora')->orderBy('semestre', 'asc')->first();
				if (!$configuracion) {
					continue;
				}

				$fechaInicioCfg = !empty($configuracion->fecha_inicio) ? Carbon::parse($configuracion->fecha_inicio)->startOfDay() : null;
				$fechaFinCfg = !empty($configuracion->fecha_fin) ? Carbon::parse($configuracion->fecha_fin)->startOfDay() : null;
				if (!$fechaInicioCfg) {
					continue;
				}
				if ($fechaInicioCfg->gt($hoy)) {
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
					continue;
				}

				$fechaCalculo = $fechaFinCfg ? ($hoy->lt($fechaFinCfg) ? $hoy : $fechaFinCfg) : $hoy;
				if ($fechaCalculo->lt($fechaInicioCfg)) {
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
			if (preg_match('/-(\d+)/', (string)$row->gestion, $m)) {
				return (string)((int)$m[1]);
			}
		}
		if (isset($row->cod_curso) && $row->cod_curso) {
			$cc = (string)$row->cod_curso;
			if (preg_match('/(\d{3})/', $cc, $m)) {
				$n = (int)$m[1];
				if ($n >= 200) {
					return '2';
				}
				return '1';
			}
			if (preg_match('/(\d)/', $cc, $m)) {
				return (string)((int)$m[1]);
			}
		}
		return null;
	}
}
