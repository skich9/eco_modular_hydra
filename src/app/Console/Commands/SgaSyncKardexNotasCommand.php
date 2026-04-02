<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncKardexNotasCommand extends Command
{
	protected $signature = 'sga:sync-kardex-notas {source=all} {--gestion=} {--chunk=1000} {--dry-run}';
	protected $description = 'Sincroniza kardex_notas desde SGA hacia la tabla local kardex_notas (source: sga_elec|sga_mec|all, --gestion=1/2026)';

	public function handle(SgaSyncRepository $repo)
	{
		$sourceArg = strtolower((string) $this->argument('source'));
		$chunk = (int) $this->option('chunk');
		$dry = (bool) $this->option('dry-run');
		$gestion = $this->option('gestion');

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
				$msg = "Sincronizando kardex_notas desde {$src}";
				if ($gestion) {
					$msg .= " para gestión {$gestion}";
				}
				$this->info($msg . "...");
				$res = $repo->syncKardexNotas($src, $chunk, $dry, $gestion);
				$summary[$src] = $res;
				$sk = isset($res['skipped']) ? $res['skipped'] : 0;
				$this->info("[{$src}] OK total={$res['total']} inserted={$res['inserted']} updated={$res['updated']} skipped={$sk}");
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
