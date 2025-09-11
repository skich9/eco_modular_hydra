<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncAllParamsCommand extends Command
{
	protected $signature = 'sin:sync-catalogs {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza todas las paramétricas estándar del SIAT y almacena en sin_datos_sincronizacion';

	public function handle(SyncRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$summary = $repo->syncAllParametricas($pv);
			$this->info('Sincronizacion de catalogos OK');
			$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error sincronizando catálogos: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
