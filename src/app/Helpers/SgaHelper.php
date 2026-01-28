<?php

namespace App\Helpers;

use App\Models\Pensum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SgaHelper
{
    public static function getStackTrackeException(Exception $e)
    {
        $trace = explode("\n", $e->getTraceAsString());
        // reverse array to make steps line up chronologically
        $trace = array_reverse($trace);
        array_shift($trace); // remove {main}
        array_pop($trace); // remove call to this method
        $length = count($trace);
        $result = array();

        for ($i = 0; $i < $length; $i++)
        {
            $result[] = ($i + 1)  . ')' . substr($trace[$i], strpos($trace[$i], ' ')); // replace '#someNum' with '$i)', set the right ordering
        }
        return implode("\n\t", $result);
    }
	/**
	 * Determina la conexión de base de datos SGA según el código de pensum
	 *
	 * @param string $codPensum Código del pensum (ej: 'EEA-19', 'MEA-1998')
	 * @return string Nombre de la conexión ('sga_elec' o 'sga_mec')
	 */
	public static function getConnectionByPensum($codPensum)
	{
		try {
			$pensum = Pensum::where('cod_pensum', $codPensum)->first();

			if ($pensum && $pensum->codigo_carrera) {
				$carrera = strtoupper($pensum->codigo_carrera);

				// MEA = Mecánica Automotriz -> usar base de datos de Mecánica
				if ($carrera === 'MEA') {
					return 'sga_mec';
				}

				// EEA = Electricidad y Electrónica -> usar base de datos de Electrónica
				if ($carrera === 'EEA') {
					return 'sga_elec';
				}

				// SEA u otras carreras -> usar Electrónica por defecto
				return 'sga_elec';
			}
		} catch (\Throwable $e) {
			Log::warning('SgaHelper: Error al determinar conexión por pensum', [
				'cod_pensum' => $codPensum,
				'error' => $e->getMessage()
			]);
		}

		// Fallback: usar Electrónica por defecto
		return 'sga_elec';
	}

	/**
	 * Determina la URL base del API SGA según el código de pensum
	 *
	 * @param string $codPensum Código del pensum
	 * @return string URL base del SGA
	 */
	public static function getApiUrlByPensum($codPensum)
	{
		try {
			$pensum = Pensum::where('cod_pensum', $codPensum)->first();

			if ($pensum && $pensum->codigo_carrera) {
				$carrera = strtoupper($pensum->codigo_carrera);

				// MEA = Mecánica Automotriz -> usar URL de Mecánica
				if ($carrera === 'MEA') {
					return env('SGA_MECANICA_URL', env('SGA_BASE_URL'));
				}
			}
		} catch (\Throwable $e) {
			Log::warning('SgaHelper: Error al determinar URL por pensum', [
				'cod_pensum' => $codPensum,
				'error' => $e->getMessage()
			]);
		}

		// Fallback: usar Electrónica por defecto
		return env('SGA_BASE_URL');
	}

	/**
	 * Determina la conexión de base de datos SGA según el código de estudiante (CETA)
	 * Busca la inscripción más reciente del estudiante para determinar su pensum
	 *
	 * @param string|int $codCeta Código del estudiante
	 * @return string Nombre de la conexión ('sga_elec' o 'sga_mec')
	 */
	public static function getConnectionByCeta($codCeta)
	{
		try {
			// Buscar la inscripción más reciente del estudiante
			$inscripcion = DB::table('registro_inscripcion')
				->where('cod_ceta', $codCeta)
				->orderBy('gestion', 'desc')
				->orderBy('id_inscripcion', 'desc')
				->first();

			if ($inscripcion && $inscripcion->cod_pensum) {
				return self::getConnectionByPensum($inscripcion->cod_pensum);
			}
		} catch (\Throwable $e) {
			Log::warning('SgaHelper: Error al determinar conexión por CETA', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
		}

		// Fallback: usar Electrónica por defecto
		return 'sga_elec';
	}

	/**
	 * Determina la URL base del API SGA según el código de estudiante (CETA)
	 *
	 * @param string|int $codCeta Código del estudiante
	 * @return string URL base del SGA
	 */
	public static function getApiUrlByCeta($codCeta)
	{
		try {
			// Buscar la inscripción más reciente del estudiante
			$inscripcion = DB::table('registro_inscripcion')
				->where('cod_ceta', $codCeta)
				->orderBy('gestion', 'desc')
				->orderBy('id_inscripcion', 'desc')
				->first();

			if ($inscripcion && $inscripcion->cod_pensum) {
				return self::getApiUrlByPensum($inscripcion->cod_pensum);
			}
		} catch (\Throwable $e) {
			Log::warning('SgaHelper: Error al determinar URL por CETA', [
				'cod_ceta' => $codCeta,
				'error' => $e->getMessage()
			]);
		}

		// Fallback: usar Electrónica por defecto
		return env('SGA_BASE_URL');
	}

	/**
	 * Obtiene todas las conexiones SGA disponibles
	 *
	 * @return array Array de nombres de conexiones
	 */
	public static function getAllConnections()
	{
		return ['sga_elec', 'sga_mec'];
	}
}
