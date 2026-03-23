<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncCobrosMultaCommand extends Command
{
	protected $signature = 'sga:sync-cobros-multa
		{gestion : Gestion a sincronizar (ej: 2/2025)}
		{--source=all : sga_elec|sga_mec|all}
		{--chunk=1000}
		{--dry-run}
		{--trace : Registra trazabilidad en sga_sync_cobros aun en dry-run}
		{--cod-ceta= : Filtrar por cod_ceta}
		{--cod-pensum= : Filtrar por cod_pensum}';

	protected $description = 'Sincroniza cobros de mora desde SGA (pago_multa) hacia cobro/cobros_detalle_multa y actualiza asignacion_mora';

	public function handle(SgaSyncRepository $repo)
	{
		$gestion = (string) $this->argument('gestion');
		$sourceArg = strtolower((string) $this->option('source'));
		$chunk = (int) $this->option('chunk');
		$dry = (bool) $this->option('dry-run');
		$trace = (bool) $this->option('trace');
		$codCeta = $this->option('cod-ceta');
		$codPensum = $this->option('cod-pensum');

		$codCeta = $codCeta !== null && $codCeta !== '' ? (int) $codCeta : null;
		$codPensum = $codPensum !== null && trim((string)$codPensum) !== '' ? (string) $codPensum : null;

		$sources = [];
		switch ($sourceArg) {
			case 'sga_elec': $sources = ['sga_elec']; break;
			case 'sga_mec': $sources = ['sga_mec']; break;
			case 'all':
			default: $sources = ['sga_elec','sga_mec']; break;
		}

		$summary = [];
		foreach ($sources as $src) {
			try {
				$res = $repo->syncCobrosPagoMultaPorGestion($src, $gestion, $chunk, $dry, $codCeta, $codPensum, $trace);
				$summary[$src] = $res;
				$skSyn = isset($res['skippedSynced']) ? $res['skippedSynced'] : 0;
				$skUsr = isset($res['skippedMissingUser']) ? $res['skippedMissingUser'] : 0;
				$skIns = isset($res['skippedMissingInscripcion']) ? $res['skippedMissingInscripcion'] : 0;
				$skMora = isset($res['skippedMissingMora']) ? $res['skippedMissingMora'] : 0;
				$errs = isset($res['errors']) ? $res['errors'] : 0;
				$mode = $dry ? ($trace ? 'DRY_RUN+TRACE' : 'DRY_RUN') : 'WRITE';
				$this->info("[{$src}] OK gestion={$gestion} mode={$mode} total={$res['total']} processed={$res['inserted']} already_synced={$skSyn} missing_user_used_default={$skUsr} missing_inscripcion={$skIns} missing_mora={$skMora} errors={$errs}");
				if ($dry && !$trace) {
					$this->line("[{$src}] Nota: DRY_RUN no inserta en cobro/cobros_detalle_multa ni en sga_sync_cobros (use --trace para registrar trazabilidad)." );
				}
				if ($dry && $trace) {
					$this->line("[{$src}] Nota: DRY_RUN+TRACE NO inserta en cobro/cobros_detalle_multa; solo registra trazabilidad en sga_sync_cobros." );
				}
				if (!$dry) {
					$this->line("[{$src}] Nota: WRITE inserta cobros en cobro/cobros_detalle_multa y trazabilidad en sga_sync_cobros." );
				}
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
