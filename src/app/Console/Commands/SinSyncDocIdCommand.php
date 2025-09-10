<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncDocIdCommand extends Command
{
	protected $signature = 'sin:sync-docid {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza Parametrica Tipo Documento de Identidad desde SIAT piloto y la almacena en sin_datos_sincronizacion';

	public function handle(SyncRepository $repo): int
	{
		$pv = (int) $this->argument('pv');
		try {
			$res = $repo->syncTipoDocumentoIdentidad($pv);
			$this->info('Sincronizacion OK');
			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error sincronizando TipoDocumentoIdentidad: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
