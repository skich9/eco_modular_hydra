<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncActividadesCommand extends Command
{
	protected $signature = 'sin:sync-actividades {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza Actividades (CAEB) desde SIAT piloto y las almacena en sin_actividades';

	public function handle(SyncRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$res = $repo->syncActividades($pv);
			$this->info('Sincronizacion de actividades OK');
			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error sincronizando actividades: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
