<?php

namespace App\Repositories\Sin;

use App\Services\Siat\SyncService;
use Illuminate\Support\Facades\DB;
use Exception;

class SyncRepository
{
	public function __construct(
		private SyncService $sync,
		private CuisRepository $cuisRepo,
	) {}

	/**
	 * Sincroniza Parametrica Tipo Documento Identidad hacia sin_datos_sincronizacion
	 * Guarda como tipo = 'sincronizarParametricaTipoDocumentoIdentidad'
	 */
	public function syncTipoDocumentoIdentidad(int $puntoVenta = 0): array
	{
		return $this->syncParametrica('sincronizarParametricaTipoDocumentoIdentidad', $puntoVenta);
	}

	/**
	 * Método genérico para sincronizar paramétricas que devuelven RespuestaListaParametricas/listaCodigos
	 */
	public function syncParametrica(string $method, int $puntoVenta = 0): array
	{
		// Aseguramos CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$resp = $this->sync->parametrica($method, $cuis, $puntoVenta);
		$body = $resp['RespuestaListaParametricas'] ?? null;
		if (!$body) {
			throw new Exception("Respuesta inválida de {$method}: " . json_encode($resp));
		}
		$lista = $body['listaCodigos'] ?? [];
		$items = $this->normalizeLista($lista);

		$rows = [];
		foreach ($items as $it) {
			$codigo = (string) ($it['codigoClasificador'] ?? '');
			$desc = (string) ($it['descripcion'] ?? '');
			if ($codigo === '') continue;
			$rows[] = [
				'tipo' => $method,
				'codigo_clasificador' => $codigo,
				'descripcion' => $desc,
			];
		}

		if ($rows) {
			DB::table('sin_datos_sincronizacion')->upsert(
				$rows,
				['tipo', 'codigo_clasificador'],
				['descripcion']
			);
		}

		return [
			'count' => count($rows),
			'tipo' => $method,
		];
	}

	/**
	 * Ejecuta un set de paramétricas estándar en secuencia y retorna resumen por tipo
	 */
	public function syncAllParametricas(int $puntoVenta = 0): array
	{
		$methods = [
			'sincronizarParametricaTipoPuntoVenta',
			'sincronizarParametricaEventosSignificativos',
			'sincronizarParametricaUnidadMedida',
			'sincronizarParametricaTiposFactura',
			'sincronizarParametricaTipoDocumentoSector',
			'sincronizarParametricaMotivoAnulacion',
			'sincronizarParametricaTipoEmision',
			'sincronizarParametricaTipoDocumentoIdentidad',
		];

		$summary = [];
		foreach ($methods as $m) {
			$summary[$m] = $this->syncParametrica($m, $puntoVenta);
		}
		return $summary;
	}

	/**
	 * Sincroniza Lista Leyendas de Factura hacia sin_list_leyenda_factura
	 */
	public function syncLeyendasFactura(int $puntoVenta = 0): array
	{
		// Aseguramos CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$resp = $this->sync->leyendasFactura($cuis, $puntoVenta);
		$body = $resp['RespuestaListaParametricasLeyendas'] ?? null;
		if (!$body) {
			throw new Exception('Respuesta inválida de sincronizarListaLeyendasFactura: ' . json_encode($resp));
		}
		$lista = $body['listaLeyendas'] ?? [];
		$items = $this->normalizeLeyendas($lista);

		$rows = [];
		foreach ($items as $it) {
			$codAct = (string) ($it['codigoActividad'] ?? '');
			$descLey = (string) ($it['descripcionLeyenda'] ?? '');
			if ($codAct === '' || $descLey === '') continue;
			$rows[] = [
				'codigo_actividad' => $codAct,
				'descripcion_leyenda' => $descLey,
			];
		}

		// Insertar evitando duplicados (PK compuesta: codigo_actividad + descripcion_leyenda)
		foreach ($rows as $r) {
			DB::table('sin_list_leyenda_factura')
				->updateOrInsert(
					['codigo_actividad' => $r['codigo_actividad'], 'descripcion_leyenda' => $r['descripcion_leyenda']],
					[]
				);
		}

		return [
			'count' => count($rows),
			'tabla' => 'sin_list_leyenda_factura',
		];
	}

	private function normalizeLista($lista): array
	{
		// Puede venir como objeto único o como arreglo de objetos
		if (is_array($lista)) {
			// si es asociativo con codigoClasificador, envolver
			if (isset($lista['codigoClasificador'])) {
				return [
					[
						'codigoClasificador' => $lista['codigoClasificador'],
						'descripcion' => $lista['descripcion'] ?? null,
					],
				];
			}
			return $lista;
		}
		return [];
	}

	private function normalizeLeyendas($lista): array
	{
		if (is_array($lista)) {
			// Asociativo único
			if (isset($lista['codigoActividad']) || isset($lista['descripcionLeyenda'])) {
				return [[
					'codigoActividad' => $lista['codigoActividad'] ?? '',
					'descripcionLeyenda' => $lista['descripcionLeyenda'] ?? '',
				]];
			}
			return $lista;
		}
		return [];
	}
}
