<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncMetodoPagoCommand extends Command
{
	protected $signature = 'sin:sync-metodo-pago {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza Tipo Método de Pago desde SIAT y lo mapea contra formas_cobro para poblar sin_forma_cobro';

	public function handle(SyncRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$res = $repo->syncTipoMetodoPago($pv);
			$this->info('Sincronizacion de Tipo Metodo de Pago OK');
			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error sincronizando Tipo Metodo de Pago: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
