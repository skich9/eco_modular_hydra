<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;

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

		if (!$codPensum || !$gestion || !$codCurso || !$codInscrip) return;

		// Derivar semestre y turno a partir de cod_curso (p.ej.: 04-MTZ-101M => sem 1, turno M)
		$segment = $codCurso;
		if (str_contains($codCurso, '-')) {
			$parts = explode('-', $codCurso);
			$segment = end($parts) ?: $codCurso;
		}
		$segment = trim($segment);
		$turnoChar = strtoupper(substr($segment, -1));
		$turnoMap = [ 'M' => 'MANANA', 'T' => 'TARDE', 'N' => 'NOCHE' ];
		$turno = $turnoMap[$turnoChar] ?? null;
		// Extraer primer dígito como semestre, p.ej. 101M => 1
		$digits = preg_replace('/\D+/', '', $segment);
		$semestre = $digits !== '' ? intval(substr($digits, 0, 1)) : null;

		if (!$turno || !$semestre) return;

		// Mapear tipo_inscripcion a tipo_costo
		$tipoCosto = match ($tipoIns) {
			'ARRASTRE' => 'Materia Arrastre',
			default => 'Costo Semestral',
		};

		// Buscar costo semestral que coincida
		$costo = CostoSemestral::query()
			->where('cod_pensum', $codPensum)
			->where('gestion', $gestion)
			->where('semestre', $semestre)
			->where('turno', $turno)
			->where('tipo_costo', $tipoCosto)
			->first();
		if (!$costo) return;

		// Evitar duplicados
		$exists = AsignacionCostos::query()
			->where('cod_inscrip', $codInscrip)
			->where('id_costo_semestral', $costo->id_costo_semestral)
			->exists();
		if ($exists) return;

		AsignacionCostos::create([
			'cod_pensum' => $codPensum,
			'cod_inscrip' => $codInscrip,
			'monto' => $costo->monto_semestre,
			'id_costo_semestral' => $costo->id_costo_semestral,
			'estado' => true,
		]);
	}
}
