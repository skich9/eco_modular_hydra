<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncDescuentosSgaCommand extends Command
{
	protected $signature = 'sga:syncDescuentosSga {source=all : sga_elec|sga_mec|all} {--gestion=} {--chunk=1000} {--dry-run} {--dry_run}';
	protected $description = 'Sincroniza descuentos aplicados en SGA (kardex_economico + descuento_parcial) hacia descuentos/descuento_detalle';

	public function handle(SgaSyncRepository $repo): int
	{
		$sourceArg = strtolower((string) $this->argument('source'));
		$gestion = trim((string) $this->option('gestion'));
		$chunk = (int) $this->option('chunk');
		$dry = (bool) ($this->option('dry-run') || $this->option('dry_run'));

		try {
			$res = $repo->syncDescuentosSga($sourceArg, $chunk, $dry, $gestion !== '' ? $gestion : null);
			$error = isset($res['error']) ? (string) $res['error'] : '';

			if ($error !== '') {
				$this->error('Error: ' . $error);
			}

			$this->info(
				'total=' . ($res['total_rows'] ?? 0)
				. ' semestrales=' . ($res['semestrales_rows'] ?? 0)
				. ' parciales=' . ($res['parciales_rows'] ?? 0)
				. ' maestros_ins=' . ($res['maestros_inserted'] ?? 0)
				. ' maestros_upd=' . ($res['maestros_updated'] ?? 0)
				. ' det_ins=' . ($res['detalles_inserted'] ?? 0)
				. ' det_upd=' . ($res['detalles_updated'] ?? 0)
				. ' skip_ins=' . ($res['skipped_missing_inscripcion'] ?? 0)
				. ' skip_cuota=' . ($res['skipped_missing_cuota'] ?? 0)
				. ' skip_conflict=' . ($res['skipped_conflict'] ?? 0)
				. ' skip_invalid=' . ($res['skipped_invalid'] ?? 0)
				. ' cod_beca_default=' . ($res['default_cod_beca'] ?? 'null')
			);

			$this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			return $error === '' ? self::SUCCESS : self::FAILURE;
		} catch (\Throwable $e) {
			$this->error('Error: ' . $e->getMessage());
			return self::FAILURE;
		}
	}
}
