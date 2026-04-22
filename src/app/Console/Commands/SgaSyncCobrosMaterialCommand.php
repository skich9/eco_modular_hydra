<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncCobrosMaterialCommand extends Command
{
	protected $signature = 'sga:sync-cobros-material
		{source=all : sga_elec|sga_mec|all}
		{--gestion= : Filtrar por gestion (opcional; si se omite migra todo)}
		{--chunk=1000}
		{--dry-run}
		{--trace : Registra trazabilidad en sga_sync_cobros aun en dry-run}
		{--cod-ceta= : Filtrar por cod_ceta}
		{--cod-pensum= : Filtrar por cod_pensum}';

	protected $description = 'Sincroniza cobros de MATERIAL_ADICIONAL (libros/material extra) desde SGA hacia la tabla cobro. Idempotente via sga_sync_cobros.';

	public function handle(SgaSyncRepository $repo)
	{
		$sourceArg = strtolower((string) $this->argument('source'));
		$gestion = $this->option('gestion');
		$chunk = (int) $this->option('chunk');
		$dry = (bool) $this->option('dry-run');
		$trace = (bool) $this->option('trace');
		$codCeta = $this->option('cod-ceta');
		$codPensum = $this->option('cod-pensum');

		$gestion = $gestion !== null && trim((string) $gestion) !== '' ? (string) $gestion : null;
		$codCeta = $codCeta !== null && $codCeta !== '' ? (int) $codCeta : null;
		$codPensum = $codPensum !== null && trim((string) $codPensum) !== '' ? (string) $codPensum : null;

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
				$res = $repo->syncCobrosMaterialAdicional($src, $gestion, $chunk, $dry, $codCeta, $codPensum, $trace);
				$summary[$src] = $res;
				$skSyn = $res['skippedSynced'] ?? 0;
				$skUsr = $res['skippedMissingUser'] ?? 0;
				$skIns = $res['skippedMissingInscripcion'] ?? 0;
				$skItm = $res['skippedMissingItem'] ?? 0;
				$errs = $res['errors'] ?? 0;
				$mode = $dry ? ($trace ? 'DRY_RUN+TRACE' : 'DRY_RUN') : 'WRITE';
				$gLabel = $gestion ?? 'ALL';
				$this->info("[{$src}] OK gestion={$gLabel} mode={$mode} total={$res['total']} processed={$res['inserted']} already_synced={$skSyn} missing_user_used_default={$skUsr} missing_inscripcion={$skIns} missing_item_null={$skItm} errors={$errs}");
				if ($dry && !$trace) {
					$this->line("[{$src}] Nota: DRY_RUN no inserta en cobro ni en sga_sync_cobros (use --trace para registrar trazabilidad).");
				}
				if ($dry && $trace) {
					$this->line("[{$src}] Nota: DRY_RUN+TRACE NO inserta en cobro; solo registra trazabilidad en sga_sync_cobros.");
				}
				if (!$dry) {
					$this->line("[{$src}] Nota: WRITE inserta cobros en cobro (cod_tipo_cobro=MATERIAL_EXTRA) y trazabilidad en sga_sync_cobros.");
				}
				if ($skUsr > 0) {
					$this->line("[{$src}] missing_user_used_default={$skUsr}: SGA.material_adicional.usuario no se encontró en usuarios.nickname. Se usó SYNC_DEFAULT_USER_ID.");
				}
				if ($skItm > 0) {
					$this->line("[{$src}] missing_item_null={$skItm}: no se encontró item en items_cobro para nombre_libro/insumo/concepto. cobro.id_item quedó en NULL. Ejecute antes 'sga:sync-items-cobro all' para mejorar el match.");
				}
			} catch (\Throwable $e) {
				$summary[$src] = ['error' => $e->getMessage()];
				$this->error("[{$src}] Error: " . $e->getMessage());
			}
		}

		$this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		return self::SUCCESS;
	}
}
