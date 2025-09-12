<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncDeudasCommand extends Command
{
	protected $signature = 'sga:sync-deudas {source=all : sga_elec|sga_mec|all} {--chunk=1000} {--dry-run}';
	protected $description = 'Sincroniza deudas desde SGA hacia la tabla local deudas';

	public function handle(SgaSyncRepository $repo): int
	{
		$sourceArg = strtolower((string) $this->argument('source'));
		$chunk = (int) $this->option('chunk');
		$dry = (bool) $this->option('dry-run');

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
				$res = $repo->syncDeudas($src, $chunk, $dry);
				$summary[$src] = $res;
				$skIns = $res['skipped_missing_inscripcion'] ?? 0;
				$skEst = $res['skipped_missing_estudiante'] ?? 0;
				$this->info("[{$src}] OK total={$res['total']} inserted={$res['inserted']} updated={$res['updated']} skipped_insc={$skIns} skipped_est={$skEst}");
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
