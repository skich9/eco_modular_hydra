<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\CuisRepository;

class SinCuisCommand extends Command
{
	protected $signature = 'sin:cuis {pv=0 : Codigo de punto de venta}';
	protected $description = 'Obtiene CUIS vigente o solicita uno nuevo para el punto de venta indicado';

	public function handle(CuisRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$data = $repo->getVigenteOrCreate2(2,0,$pv);
			$this->info('CUIS OK');
			$this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error CUIS: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
