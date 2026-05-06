<?php

namespace App\Services\Economico;

use App\Services\ReciboPdfService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réplica funcional de SGA {@see Comprobante_reposicion}: listados y PDF de nota de reposición con estudiante.
 */
class NotaReposicionEstudianteReimpresionService
{
	public function __construct(
		private ReciboPdfService $reciboPdfService
	) {}

	private function nombreEstudianteExpr(): string
	{
		return "CASE
			WHEN TRIM(IFNULL(e.ap_paterno,'')) = '' THEN TRIM(CONCAT(IFNULL(e.ap_materno,''), ' ', IFNULL(e.nombres,'')))
			WHEN TRIM(IFNULL(e.ap_materno,'')) = '' THEN TRIM(CONCAT(IFNULL(e.ap_paterno,''), ' ', IFNULL(e.nombres,'')))
			ELSE TRIM(CONCAT(IFNULL(e.ap_paterno,''), ' ', IFNULL(e.ap_materno,''), ' ', IFNULL(e.nombres,'')))
		END";
	}

	private function documentoExpr(): string
	{
		return "CONCAT(nr.prefijo_carrera, nr.anio_reposicion, LPAD(nr.correlativo, 5, '0'))";
	}

	/** Pensum de la inscripción más reciente por gestión/fecha; igual criterio general que recibos ({@see CobroController}). */
	private function codPensumInscripcionEstudiante(int $codCeta): ?string
	{
		if ($codCeta <= 0) {
			return null;
		}
		try {
			if (Schema::hasTable('inscripciones')) {
				$r = DB::table('inscripciones')
					->where('cod_ceta', $codCeta)
					->orderByDesc('gestion')
					->orderByDesc('fecha_inscripcion')
					->orderByDesc('cod_inscrip')
					->value('cod_pensum');
				$p = trim((string) ($r ?? ''));
				if ($p !== '') {
					return $p;
				}
			}
		} catch (\Throwable) {
		}
		try {
			if (Schema::hasTable('estudiantes')) {
				$r = DB::table('estudiantes')->where('cod_ceta', $codCeta)->value('cod_pensum');
				$p = trim((string) ($r ?? ''));

				return $p !== '' ? $p : null;
			}
		} catch (\Throwable) {
		}

		return null;
	}

	private function queryBase()
	{
		$doc = $this->documentoExpr();
		$nom = $this->nombreEstudianteExpr();

		return DB::table('nota_reposicion as nr')
			->join('estudiantes as e', 'e.cod_ceta', '=', 'nr.cod_ceta')
			->select([
				DB::raw("{$doc} AS documento"),
				'nr.correlativo',
				'nr.cont',
				'nr.usuario',
				'nr.fecha_nota',
				'nr.cod_ceta',
				DB::raw("{$nom} AS nombre_completo"),
				'nr.monto',
				'nr.concepto_adm',
				'nr.observaciones',
				'nr.nro_recibo',
				'nr.prefijo_carrera',
				'nr.anio_reposicion',
			]);
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function listarPorDocumento(string $documentoSinGuiones): array
	{
		if (!Schema::hasTable('nota_reposicion')) {
			return [];
		}
		$doc = preg_replace('/\s+/u', '', $documentoSinGuiones ?? '') ?? '';

		$rows = $this->queryBase()
			->whereRaw("{$this->documentoExpr()} = ?", [$doc])
			->orderBy('nr.correlativo')
			->get();

		return $this->filasRespuesta($rows);
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function listarPorFechaDmY(string $fechaIniDmY, string $fechaFinDmY): array
	{
		if (!Schema::hasTable('nota_reposicion')) {
			return [];
		}
		$ini = Carbon::createFromFormat('d/m/Y', trim($fechaIniDmY))->startOfDay();
		$fin = Carbon::createFromFormat('d/m/Y', trim($fechaFinDmY))->endOfDay();

		$rows = $this->queryBase()
			->whereBetween('nr.fecha_nota', [$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')])
			->orderByDesc('nr.correlativo')
			->get();

		return $this->filasRespuesta($rows);
	}

	/**
	 * @return array<int, array<string,mixed>>
	 */
	public function listarPorCodCeta(int $codCeta): array
	{
		if (!Schema::hasTable('nota_reposicion')) {
			return [];
		}
		$rows = $this->queryBase()
			->where('nr.cod_ceta', $codCeta)
			->orderByDesc('nr.correlativo')
			->get();

		return $this->filasRespuesta($rows);
	}

	/**
	 * @param  \Illuminate\Support\Collection<int, object>|\Illuminate\Support\Collection<int,\stdClass>  $rows
	 * @return array<int, array<string,mixed>>
	 */
	private function filasRespuesta($rows): array
	{
		$out = [];
		foreach ($rows as $i => $fila) {
			$f = (array) $fila;
			$fechaFormateada = '';
			try {
				if (!empty($f['fecha_nota'])) {
					$d = Carbon::parse($f['fecha_nota']);
					$fechaFormateada = $d->format('d/m/Y H:i:s');
				}
			} catch (\Throwable) {
				$fechaFormateada = (string) ($f['fecha_nota'] ?? '');
			}
			$out[] = [
				'nro' => $i + 1,
				'documento' => (string) ($f['documento'] ?? ''),
				'cont' => isset($f['cont']) ? (int) $f['cont'] : 0,
				'fecha_registro' => $fechaFormateada,
				'usuario' => (string) ($f['usuario'] ?? ''),
				'cod_ceta' => isset($f['cod_ceta']) ? (int) $f['cod_ceta'] : null,
				'estudiante' => (string) ($f['nombre_completo'] ?? ''),
				'monto' => isset($f['monto']) ? (float) $f['monto'] : 0.0,
				'concepto' => (string) ($f['concepto_adm'] ?? ''),
				'observaciones' => (string) ($f['observaciones'] ?? ''),
				'nro_recibo' => (string) ($f['nro_recibo'] ?? ''),
			];
		}

		return $out;
	}

	public function generarPdf(string $numDocSinGuiones, int $cont): string
	{
		if (!Schema::hasTable('nota_reposicion')) {
			throw new \RuntimeException('Tabla nota_reposicion no disponible.');
		}
		$docKey = preg_replace('/\s+/u', '', $numDocSinGuiones) ?? '';

		$row = DB::table('nota_reposicion as nr')
			->join('estudiantes as e', 'e.cod_ceta', '=', 'nr.cod_ceta')
			->whereRaw("{$this->documentoExpr()} = ?", [$docKey])
			->where('nr.cont', $cont)
			->select([
				'nr.correlativo',
				'nr.anio_reposicion',
				'nr.prefijo_carrera',
				'nr.fecha_nota',
				'nr.cod_ceta',
				'nr.monto',
				'nr.concepto_adm',
				'nr.concepto_est',
				'nr.observaciones',
				'nr.nro_recibo',
				'nr.usuario',
				DB::raw($this->nombreEstudianteExpr().' AS nom_estudiante'),
			])
			->first();

		if (!$row) {
			throw new \InvalidArgumentException('No se encontró la nota de reposición indicada.');
		}

		$prefijo = (string) ($row->prefijo_carrera ?? '');
		$codCetaInt = (int) ($row->cod_ceta ?? 0);
		$codPensumIns = $this->codPensumInscripcionEstudiante($codCetaInt);
		$carreraNombre = $codPensumIns !== null
			? $this->reciboPdfService->resolveCarreraNombre($codPensumIns)
			: '';
		if ($carreraNombre === '' && Schema::hasTable('carrera') && $prefijo !== '') {
			$carreraNombre = (string) (DB::table('carrera')->where('prefijo_matricula', $prefijo)->value('nombre') ?? '');
		}

		$usuario = (string) ($row->usuario ?? '');
		$nombreEst = (string) ($row->nom_estudiante ?? '');
		$codCeta = $row->cod_ceta ?? '';
		$monto = (float) ($row->monto ?? 0);
		$detalle = (string) ($row->concepto_adm ?? '');
		$detalleEst = trim((string) ($row->concepto_est ?? ''));
		$obs = (string) ($row->observaciones ?? '');
		$reciboR = trim((string) ($row->nro_recibo ?? ''));
		if ($reciboR === '') {
			$reciboR = 'S/N';
		}

		try {
			$fechaNota = Carbon::parse($row->fecha_nota);
		} catch (\Throwable) {
			$fechaNota = Carbon::now('America/La_Paz');
		}

		return $this->reciboPdfService->buildPdfNotaReposicionEstudianteReimpresion(
			$fechaNota,
			$carreraNombre,
			(int) ($row->correlativo ?? 0),
			$nombreEst,
			$codCeta,
			$monto,
			$detalle,
			$detalleEst !== '' ? $detalleEst : null,
			$obs,
			$reciboR,
			$usuario,
		);
	}
}
