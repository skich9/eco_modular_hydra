<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Chequeos previos (read-only) antes de migrar. NO escribe nada en el SGA.
 *
 * 1. Conectividad: las conexiones sga_elec / sga_mec responden y reportan a qué BD apuntan.
 * 2. Solape de numeración: MAX(num_factura/num_recibo) en el SGA vs MIN() del rango en sistemaEco.
 * 3. Integridad de inscripciones: los cod_inscrip del rango existen en registro_inscripcion del SGA.
 */
class PreflightService
{
    private array $conns = ['sga_elec', 'sga_mec'];

    public function __construct(private MapperHelper $mapper)
    {
    }

    /** Ejecuta todos los chequeos y devuelve un arreglo estructurado. */
    public function run(string $from, string $until): array
    {
        return [
            'conectividad'  => $this->checkConectividad(),
            'solape'        => $this->checkSolapeNumeracion($from, $until),
            'inscripciones' => $this->checkInscripciones($from, $until),
        ];
    }

    /** 1. ¿Responden las conexiones y a qué BD apuntan? */
    private function checkConectividad(): array
    {
        $out = [];
        foreach ($this->conns as $conn) {
            try {
                $db = DB::connection($conn)->selectOne('SELECT current_database() AS db');
                $out[$conn] = ['ok' => true, 'database' => $db->db ?? null, 'error' => null];
            } catch (\Throwable $e) {
                $out[$conn] = ['ok' => false, 'database' => null, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * 2. Colisión REAL de numeración por PK. Como la factura/recibo de sistemaEco se inserta
     * con su mismo número, la colisión solo ocurre si la clave ya existe en el SGA:
     *   - factura PK = (num_factura, anio, es_manual)
     *   - recibo  PK = (num_recibo, anio)
     * Se enruta cada documento a su conexión (EEA/MEA) vía los cobros que lo referencian
     * y se cuenta cuántos chocan con un registro existente en esa BD del SGA.
     */
    private function checkSolapeNumeracion(string $from, string $until): array
    {
        $out = ['sga_elec' => $this->initSolape(), 'sga_mec' => $this->initSolape()];

        // ---- FACTURA: agrupar por conn + (anio, es_manual). Ruteo por cod_ceta. ----
        $facturasPorConn = ['sga_elec' => [], 'sga_mec' => []]; // [conn]["anio|esman"] = [num,...]
        $factSinRuta = 0;
        DB::connection(MapperHelper::SOURCE_CONN)->table('factura')
            ->select('nro_factura', 'anio', 'es_manual', 'cod_ceta')
            ->whereBetween('fecha_emision', [$from . ' 00:00:00', $until . ' 23:59:59'])
            ->orderBy('nro_factura')
            ->chunk(1000, function ($rows) use (&$facturasPorConn, &$factSinRuta) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                    if (!$conn) { $factSinRuta++; continue; }
                    $key = $r->anio . '|' . ((int) $r->es_manual);
                    $facturasPorConn[$conn][$key][] = (int) $r->nro_factura;
                }
            });

        // ---- RECIBO: agrupar por conn + anio. Ruteo por cod_ceta. ----
        // Se filtra por fecha_cobro del cobro relacionado (NO por created_at del recibo),
        // porque created_at refleja la fecha de sincronización, no la fecha real del cobro.
        $recibosPorConn = ['sga_elec' => [], 'sga_mec' => []]; // [conn][anio] = [num,...]
        $recSinRuta = 0;
        $nrosRecibo = DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->whereBetween('fecha_cobro', [$from . ' 00:00:00', $until . ' 23:59:59'])
            ->whereNotNull('nro_recibo')
            ->distinct()
            ->pluck('nro_recibo')
            ->all();
        if (!empty($nrosRecibo)) {
            foreach (array_chunk($nrosRecibo, 1000) as $lote) {
                DB::connection(MapperHelper::SOURCE_CONN)->table('recibo')
                    ->select('nro_recibo', 'anio', 'cod_ceta')
                    ->whereIn('nro_recibo', $lote)
                    ->orderBy('nro_recibo')
                    ->chunk(1000, function ($rows) use (&$recibosPorConn, &$recSinRuta) {
                        foreach ($rows as $r) {
                            $conn = $this->mapper->resolveConnByCodCeta($r->cod_ceta);
                            if (!$conn) { $recSinRuta++; continue; }
                            $recibosPorConn[$conn][(int) $r->anio][] = (int) $r->nro_recibo;
                        }
                    });
            }
        }

        foreach ($this->conns as $conn) {
            try {
                // Factura: contar colisiones por (anio, es_manual)
                $totFact = 0; $colFact = 0;
                foreach ($facturasPorConn[$conn] as $key => $nums) {
                    [$anio, $esMan] = explode('|', $key);
                    $totFact += count($nums);
                    foreach (array_chunk($nums, 1000) as $lote) {
                        $colFact += DB::connection($conn)->table('factura')
                            ->where('anio', (int) $anio)
                            ->where('es_manual', (bool) $esMan)
                            ->whereIn('num_factura', $lote)
                            ->count();
                    }
                }

                // Recibo: contar colisiones por anio
                $totRec = 0; $colRec = 0;
                foreach ($recibosPorConn[$conn] as $anio => $nums) {
                    $totRec += count($nums);
                    foreach (array_chunk($nums, 1000) as $lote) {
                        $colRec += DB::connection($conn)->table('recibo')
                            ->where('anio', (int) $anio)
                            ->whereIn('num_recibo', $lote)
                            ->count();
                    }
                }

                $out[$conn] = [
                    'factura' => ['en_rango' => $totFact, 'colisiones' => $colFact, 'ok' => $colFact === 0],
                    'recibo'  => ['en_rango' => $totRec, 'colisiones' => $colRec, 'ok' => $colRec === 0],
                    'error'   => null,
                ];
            } catch (\Throwable $e) {
                $out[$conn] = ['error' => $e->getMessage()];
            }
        }
        $out['_sin_ruta'] = ['factura' => $factSinRuta, 'recibo' => $recSinRuta];
        return $out;
    }

    private function initSolape(): array
    {
        return [
            'factura' => ['en_rango' => 0, 'colisiones' => 0, 'ok' => true],
            'recibo'  => ['en_rango' => 0, 'colisiones' => 0, 'ok' => true],
            'error'   => null,
        ];
    }

    /**
     * 3. ¿Existen en el SGA los cod_inscrip de los cobros del rango?
     * Cuenta cuántos cobros del rango referencian una inscripción ausente en registro_inscripcion.
     */
    private function checkInscripciones(string $from, string $until): array
    {
        $out = [];

        // Agrupar source_cod_inscrip distintos del rango por conexión (según cod_pensum).
        // IMPORTANTE: cobro.cod_inscrip es la PK interna de sistemaEco; el cod_inscrip real
        // del SGA (el que existe en registro_inscripcion) es inscripciones.source_cod_inscrip.
        $porConn = ['sga_elec' => [], 'sga_mec' => []];

        DB::connection(MapperHelper::SOURCE_CONN)->table('cobro')
            ->select('cobro.cod_inscrip', 'cobro.cod_pensum', 'inscripciones.source_cod_inscrip')
            ->join('inscripciones', 'inscripciones.cod_inscrip', '=', 'cobro.cod_inscrip')
            ->whereBetween('cobro.fecha_cobro', [$from . ' 00:00:00', $until . ' 23:59:59'])
            ->whereNotNull('cobro.cod_inscrip')
            ->whereNotNull('inscripciones.source_cod_inscrip')
            ->distinct()
            ->orderBy('cobro.cod_inscrip')
            ->chunk(1000, function ($rows) use (&$porConn) {
                foreach ($rows as $r) {
                    $conn = $this->mapper->resolveConnectionByPensum($r->cod_pensum);
                    if ($conn) {
                        $porConn[$conn][(int) $r->source_cod_inscrip] = true;
                    }
                }
            });

        foreach ($this->conns as $conn) {
            $ids = array_keys($porConn[$conn]);
            $total = count($ids);
            if ($total === 0) {
                $out[$conn] = ['total' => 0, 'faltantes' => 0, 'ok' => true, 'ejemplos' => [], 'error' => null];
                continue;
            }
            try {
                $faltantes = [];
                foreach (array_chunk($ids, 1000) as $lote) {
                    $existentes = DB::connection($conn)->table('registro_inscripcion')
                        ->whereIn('cod_inscrip', $lote)
                        ->pluck('cod_inscrip')
                        ->all();
                    $existentesSet = array_flip(array_map('intval', $existentes));
                    foreach ($lote as $id) {
                        if (!isset($existentesSet[$id])) {
                            $faltantes[] = $id;
                        }
                    }
                }
                $out[$conn] = [
                    'total'     => $total,
                    'faltantes' => count($faltantes),
                    'ok'        => count($faltantes) === 0,
                    'ejemplos'  => array_slice($faltantes, 0, 10),
                    'error'     => null,
                ];
            } catch (\Throwable $e) {
                $out[$conn] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    }
}
