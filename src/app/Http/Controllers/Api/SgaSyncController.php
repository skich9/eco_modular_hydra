<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Repositories\Sga\SgaSyncRepository;

class SgaSyncController extends Controller
{
	/**
	 * Sincroniza becas y descuentos desde SGA (tabla de_becas) hacia las tablas locales
	 * def_descuentos y def_descuentos_beca.
	 *
	 * Request:
	 * - source: sga_elec|sga_mec|all (por defecto: all)
	 * - chunk: tamaño de lote (por defecto: 1000)
	 * - dry_run: bool (por defecto: false)
	 */
	public function syncBecasDescuentos(Request $request, SgaSyncRepository $repo)
	{
		try {
			$sourceArg = strtolower((string) $request->input('source', 'all'));
			$chunk = (int) $request->input('chunk', 1000);
			$dry = (bool) $request->boolean('dry_run', false);

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
					$res = $repo->syncBecasDescuentos($src, $chunk, $dry);
					$summary[$src] = $res;
				} catch (\Throwable $e) {
					$summary[$src] = ['error' => $e->getMessage()];
				}
			}

			return response()->json([
				'success' => true,
				'data' => $summary,
				'message' => 'Sincronización de becas y descuentos ejecutada'
			]);
		} catch (\Throwable $e) {
			Log::error('Error en syncBecasDescuentos: '.$e->getMessage());
			return response()->json([
				'success' => false,
				'message' => 'Error al sincronizar becas y descuentos: '.$e->getMessage()
			], 500);
		}
	}
}
