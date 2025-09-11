<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncLeyendasCommand extends Command
{
	protected $signature = 'sin:sync-leyendas {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza Lista de Leyendas de Factura desde SIAT piloto y las almacena en sin_list_leyenda_factura';

	public function handle(SyncRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$res = $repo->syncLeyendasFactura($pv);
			$this->info('Sincronizacion de leyendas OK');
			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error sincronizando Leyendas: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
