<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncDocsCommand extends Command
{
	protected $signature = 'sga:sync-docs {source=all : sga_elec|sga_mec|all} {--chunk=1000} {--dry-run}';
	protected $description = 'Sincroniza doc_estudiante y doc_presentados desde SGA (Electrónica/Mecánica) hacia las tablas locales';

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
				$this->info("[{$src}] Sincronizando doc_estudiante...");
				$cat = $repo->syncDocEstudiante($src, $chunk, $dry);
				$this->info("[{$src}] doc_estudiante: total={$cat['total']} inserted={$cat['inserted']}");

				$this->info("[{$src}] Sincronizando doc_presentados...");
				$prs = $repo->syncDocPresentados($src, $chunk, $dry);
				$this->info("[{$src}] doc_presentados: total={$prs['total']} inserted={$prs['inserted']} updated={$prs['updated']} skipped={$prs['skipped']}");

				$summary[$src] = ['doc_estudiante' => $cat, 'doc_presentados' => $prs];
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
