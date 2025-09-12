<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncInscripcionesCommand extends Command
{
	protected $signature = 'sga:sync-inscripciones {source=all : sga_elec|sga_mec|all} {--chunk=1000} {--dry-run}';
	protected $description = 'Sincroniza inscripciones (registro_inscripcion) desde SGA hacia la tabla inscripciones con trazabilidad';

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
				$res = $repo->syncInscripciones($src, $chunk, $dry);
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
