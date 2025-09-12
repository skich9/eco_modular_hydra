<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncStudentsCommand extends Command
{
	protected $signature = 'sga:sync-students {source=all : sga_elec|sga_mec|all} {--chunk=1000} {--dry-run}';
	protected $description = 'Sincroniza estudiantes desde SGA (Electronica/Mecanica) hacia la tabla estudiantes de la app';

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
				$res = $repo->syncEstudiantes($src, $chunk, $dry);
				$summary[$src] = $res;
				$this->info("[{$src}] OK total={$res['total']} inserted={$res['inserted']} updated={$res['updated']}");
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
