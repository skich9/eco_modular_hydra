<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sin\SyncRepository;

class SinSyncParamCommand extends Command
{
	protected $signature = 'sin:sync-param {method : Metodo SIAT, p.ej. sincronizarParametricaTipoPuntoVenta} {pv=0 : Codigo de punto de venta}';
	protected $description = 'Sincroniza una paramétrica del SIAT (document/literal) y la almacena en sin_datos_sincronizacion';

	private array $allowed = [
		'sincronizarParametricaTipoPuntoVenta',
		'sincronizarParametricaEventosSignificativos',
		'sincronizarParametricaUnidadMedida',
		'sincronizarParametricaTiposFactura',
		'sincronizarParametricaTipoDocumentoSector',
		'sincronizarParametricaMotivoAnulacion',
		'sincronizarParametricaTipoEmision',
		'sincronizarParametricaTipoDocumentoIdentidad',
	];

	public function handle(SyncRepository $repo): int
	{
		$method = (string) $this->argument('method');
		$pv = (int) $this->argument('pv');

		if (!in_array($method, $this->allowed, true)) {
			$this->error('Metodo no permitido. Permitidos: ' . implode(', ', $this->allowed));
			return self::INVALID;
		}

		try {
			$res = $repo->syncParametrica($method, $pv);
			$this->info('Sincronizacion OK');
			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return self::SUCCESS;
		} catch (\Throwable $e) {
			$this->error('Error: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
