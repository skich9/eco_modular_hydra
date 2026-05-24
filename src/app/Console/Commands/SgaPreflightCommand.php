<?php

namespace App\Console\Commands;

use App\Services\SgaMigration\PreflightService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SgaPreflightCommand extends Command
{
	/** Rango por defecto de la migración histórica. */
	private const DEFAULT_FROM = '2026-04-23';
	private const DEFAULT_TO   = '2026-05-22';

	protected $signature = 'sga:preflight
		{--from= : Fecha inicial Y-m-d (default ' . self::DEFAULT_FROM . ')}
		{--to=   : Fecha final Y-m-d (default ' . self::DEFAULT_TO . ')}';

	protected $description = 'Chequeos previos (read-only) a la migración histórica de cobros: conectividad, solape de numeración e inscripciones. No escribe nada en el SGA.';

	public function handle(PreflightService $service): int
	{
		try {
			$from = $this->option('from') ? Carbon::parse($this->option('from'))->format('Y-m-d') : self::DEFAULT_FROM;
			$to   = $this->option('to') ? Carbon::parse($this->option('to'))->format('Y-m-d') : self::DEFAULT_TO;
		} catch (\Throwable) {
			$this->error('Fecha inválida. Use formato Y-m-d.');
			return self::FAILURE;
		}

		if ($from > $to) {
			$this->error('--from no puede ser mayor que --to.');
			return self::FAILURE;
		}

		$this->info("Pre-flight migración cobros sistemaEco → SGA   rango: {$from} → {$to}");
		$this->newLine();

		$res = $service->run($from, $to);
		$allOk = true;

		// 1. Conectividad
		$this->line('<comment>1) Conectividad y BD destino</comment>');
		$rows = [];
		foreach ($res['conectividad'] as $conn => $c) {
			$allOk = $allOk && $c['ok'];
			$rows[] = [$conn, $c['ok'] ? 'OK' : 'FAIL', $c['database'] ?? '—', $c['error'] ?? ''];
		}
		$this->table(['Conexión', 'Estado', 'Base de datos', 'Error'], $rows);

		// 2. Colisión de numeración por PK
		$this->line('<comment>2) Colisión de numeración por PK (factura/recibo)</comment>');
		$sinRuta = $res['solape']['_sin_ruta'] ?? ['factura' => 0, 'recibo' => 0];
		unset($res['solape']['_sin_ruta']);
		$rows = [];
		foreach ($res['solape'] as $conn => $s) {
			if (isset($s['error']) && $s['error']) {
				$allOk = false;
				$rows[] = [$conn, 'ERROR', '', '', $s['error']];
				continue;
			}
			foreach (['factura', 'recibo'] as $t) {
				$allOk = $allOk && $s[$t]['ok'];
				$rows[] = [$conn, $t, 'en_rango=' . $s[$t]['en_rango'], 'colisiones=' . $s[$t]['colisiones'], $s[$t]['ok'] ? 'OK' : 'COLISION!'];
			}
		}
		$this->table(['Conexión', 'Tabla', 'En rango', 'Colisiones', 'Estado'], $rows);
		$this->line("   sin ruta (cod_ceta sin carrera): factura={$sinRuta['factura']}  recibo={$sinRuta['recibo']}");

		// 3. Inscripciones
		$this->line('<comment>3) Inscripciones del rango existentes en el SGA</comment>');
		$rows = [];
		foreach ($res['inscripciones'] as $conn => $i) {
			if (isset($i['error'])) {
				$allOk = false;
				$rows[] = [$conn, 'ERROR', '', '', $i['error']];
				continue;
			}
			$allOk = $allOk && $i['ok'];
			$rows[] = [
				$conn,
				$i['ok'] ? 'OK' : 'FALTANTES',
				$i['total'],
				$i['faltantes'],
				$i['faltantes'] > 0 ? ('ej: ' . implode(',', $i['ejemplos'])) : '',
			];
		}
		$this->table(['Conexión', 'Estado', 'Inscrip. distintas', 'Faltantes', 'Ejemplos'], $rows);

		$this->newLine();
		if ($allOk) {
			$this->info('PRE-FLIGHT OK — listo para dry-run.');
			return self::SUCCESS;
		}
		$this->error('PRE-FLIGHT CON FALLAS — revisar antes de migrar.');
		return self::FAILURE;
	}
}
