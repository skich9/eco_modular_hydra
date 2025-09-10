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
	 * Guarda como tipo = 'TIPO_DOCUMENTO_IDENTIDAD'
	 */
	public function syncTipoDocumentoIdentidad(int $puntoVenta = 0): array
	{
		// Aseguramos CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$resp = $this->sync->tipoDocumentoIdentidad($cuis, $puntoVenta);
		$body = $resp['RespuestaListaParametricas'] ?? null;
		if (!$body) {
			throw new Exception('Respuesta inválida de sincronizarParametricaTipoDocumentoIdentidad: ' . json_encode($resp));
		}
		$lista = $body['listaCodigos'] ?? [];
		$items = $this->normalizeLista($lista);

		$rows = [];
		foreach ($items as $it) {
			$codigo = (string) ($it['codigoClasificador'] ?? '');
			$desc = (string) ($it['descripcion'] ?? '');
			if ($codigo === '') continue;
			$rows[] = [
				'tipo' => 'TIPO_DOCUMENTO_IDENTIDAD',
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
			'tipo' => 'TIPO_DOCUMENTO_IDENTIDAD',
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
}
