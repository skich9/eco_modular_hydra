<?php

namespace App\Repositories\Sin;

use App\Services\Siat\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

	/**
	 * Sincroniza Tipo Método de Pago: SIAT -> sin_forma_cobro
	 * Regla: si la descripcion SIN contiene exactamente el nombre de una fila en formas_cobro,
	 * se asigna ese id_forma_cobro como codigo interno. Si hay 0 o >1 coincidencias, usar 'Otro' si existe.
	 */
	public function syncTipoMetodoPago(int $puntoVenta = 0): array
	{
		// Aseguramos CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$resp = $this->sync->parametrica('sincronizarParametricaTipoMetodoPago', $cuis, $puntoVenta);
		$body = $resp['RespuestaListaParametricas'] ?? null;
		if (!$body) {
			throw new Exception('Respuesta inválida de sincronizarParametricaTipoMetodoPago: ' . json_encode($resp));
		}
		$lista = $body['listaCodigos'] ?? [];
		$items = $this->normalizeLista($lista);

		// Construir mapa de formas_cobro (nombre => id)
		$map = [];
		$defaultOtro = null;
		if (Schema::hasTable('formas_cobro')) {
			$fc = DB::table('formas_cobro')->select('id_forma_cobro', 'nombre')->get();
			foreach ($fc as $row) {
				$nameU = mb_strtoupper(trim((string) $row->nombre));
				$map[$nameU] = (string) $row->id_forma_cobro;
				if ($nameU === 'OTRO' || (string)$row->id_forma_cobro === 'O') {
					$defaultOtro = (string) $row->id_forma_cobro;
				}
			}
		}

		$rows = [];
		foreach ($items as $it) {
			$codigoSin = (int) ($it['codigoClasificador'] ?? 0);
			$descSin = (string) ($it['descripcion'] ?? '');
			if ($codigoSin === 0 || $descSin === '') continue;

			$interno = $this->matchFormaCobroInterno($descSin, $map, $defaultOtro);
			if ($interno === null) {
				continue;
			}

			$rows[] = [
				'codigo_sin' => $codigoSin,
				'descripcion_sin' => $descSin,
				'id_forma_cobro' => $interno,
				'activo' => true,
				'created_at' => now(),
				'updated_at' => now(),
			];
		}

		if ($rows) {
			DB::table('sin_forma_cobro')->upsert(
				$rows,
				['codigo_sin'],
				['descripcion_sin', 'id_forma_cobro', 'activo', 'updated_at']
			);
		}

		return [
			'count' => count($rows),
			'tabla' => 'sin_forma_cobro',
		];
	}

	private function matchFormaCobroInterno(string $descripcionSin, array $map, ?string $defaultOtro): ?string
	{
		$desc = trim($descripcionSin);
		// Normalizar guiones Unicode a '-'
		$desc = str_replace(["\u{2013}","\u{2014}","\u{2212}"], '-', $desc);
		// Colapsar espacios dobles y normalizar
		$desc = preg_replace('/\s+/', ' ', $desc);
		$descU = mb_strtoupper($desc);

		// Si contiene guion (cualquier variante ya normalizada) => combinación -> OTRO
		if (mb_strpos($descU, '-') !== false) {
			return $defaultOtro;
		}

		// Catálogo de nombres canónicos permitidos que mapean a un único método interno
		$canon = [
			'EFECTIVO' => 'E',
			'TARJETA' => 'L',
			'CHEQUE' => 'C',
			'DEPOSITO EN CUENTA' => 'D',
			'TRANSFERENCIA BANCARIA' => 'B',
			'TRASPASO' => 'T',
		];

		// Match estricto por igualdad del texto completo con alguno canónico
		if (array_key_exists($descU, $canon)) {
			$internal = $canon[$descU];
			// Validar que exista ese id en formas_cobro
			if (in_array($internal, array_values($map), true)) {
				return $internal;
			}
		}

		// Si contiene explícitamente OTRO/OTROS, devolver OTRO si existe
		if (preg_match('/\bOTRO(S)?\b/u', $descU)) {
			return isset($map['OTRO']) ? $map['OTRO'] : $defaultOtro;
		}

		// Cualquier otra variante (SWIFT, PAGO ONLINE, BILLETERA, CANAL, etc.) => OTRO
		return $defaultOtro;
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

	/**
	 * Sincroniza actividades (RespuestaListaActividades/listaActividades) hacia sin_actividades
	 */
	public function syncActividades(int $puntoVenta = 0): array
	{
		// Aseguramos CUIS vigente
		$cuisData = $this->cuisRepo->getVigenteOrCreate($puntoVenta);
		$cuis = $cuisData['codigo_cuis'];

		$resp = $this->sync->actividades($cuis, $puntoVenta);
		$body = $resp['RespuestaListaActividades'] ?? null;
		if (!$body) {
			throw new Exception('Respuesta inválida de sincronizarActividades: ' . json_encode($resp));
		}
		$lista = $body['listaActividades'] ?? [];
		$items = $this->normalizeActividades($lista);

		$rows = [];
		foreach ($items as $it) {
			$codigo = (string) ($it['codigoCaeb'] ?? '');
			$desc = (string) ($it['descripcion'] ?? '');
			$tipo = isset($it['tipoActividad']) ? (string) $it['tipoActividad'] : null;
			if ($codigo === '') continue;
			$rows[] = [
				'codigo_caeb' => $codigo,
				'descripcion' => $desc,
				'tipo_actividad' => $tipo,
			];
		}

		if ($rows) {
			DB::table('sin_actividades')->upsert(
				$rows,
				['codigo_caeb'],
				['descripcion', 'tipo_actividad']
			);
		}

		return [
			'count' => count($rows),
			'tabla' => 'sin_actividades',
		];
	}

	private function normalizeActividades($lista): array
	{
		if (is_array($lista)) {
			// Asociativo único
			if (isset($lista['codigoCaeb']) || isset($lista['descripcion']) || isset($lista['tipoActividad'])) {
				return [[
					'codigoCaeb' => $lista['codigoCaeb'] ?? '',
					'descripcion' => $lista['descripcion'] ?? '',
					'tipoActividad' => $lista['tipoActividad'] ?? null,
				]];
			}
			return $lista;
		}
		return [];
	}
}
