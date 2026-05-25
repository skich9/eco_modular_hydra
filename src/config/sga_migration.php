<?php

/**
 * Configuración de la migración histórica sistemaEco → SGA.
 *
 * Este archivo concentra las listas de exclusión y parámetros de comportamiento
 * que no encajan en variables de entorno porque son decisiones de negocio documentadas,
 * no credenciales ni configuraciones de infraestructura.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Facturas excluidas explícitamente por (conexión SGA, nro_factura, anio, cod_ceta)
    |--------------------------------------------------------------------------
    |
    | Registros que existen en eco_backup como COPIAS MANUALES de facturas que
    | ya fueron generadas directamente en el SGA (vía pago en línea / ServiciosOnline).
    | La secretaria las registró también en el sistema económico por error.
    |
    | La clave es SIEMPRE la combinación (conn + nro_factura + anio + cod_ceta),
    | NUNCA solo el número de factura, para no afectar registros legítimos de otros
    | estudiantes que pueden compartir el mismo nro_factura en una conexión distinta.
    |
    | Ejemplo de por qué es necesario el cod_ceta:
    |   Factura 1421 anio 2026:
    |     cod_ceta 120241205 → pensum EEA-19 → sga_elec, LEGÍTIMA (Rodriguez Siñani, mar-2026)
    |     cod_ceta 120240155 → pensum 04-MTZ-23 → sga_mec, EXCLUIR (Saravia, copia manual abr-2026)
    |
    | Formato: 'conexion_sga' => [ anio => [ nro_factura => ['cod_ceta1','cod_ceta2',...] ] ]
    |
    */
    'facturas_excluidas' => [

        // ── EEA (Electrónica) — sga_elec ────────────────────────────────────
        // Facturas generadas en EEA-220526 vía ServiciosOnline (pago en línea
        // BANCO BISA) entre 2026-04-23 y 2026-04-27. La secretaria copió esos
        // cobros manualmente en eco_backup; el SGA ya tiene la versión definitiva con CUF.
        'sga_elec' => [
            2026 => [
                // 2558 → Benavides Reynaga (EEA-26, Mens.Abril, Bs.800, VIGENTE, CUF vacío)
                //      → Orosco Camacho (EEA-19, Bs.788, ANULADA) — misma PK, colisión garantizada
                2558 => ['120261102', '220251046'],

                // 2559 → Ruiz Catari (EEA-19, Mens.Abril, Bs.808, copia manual 27-abr)
                //      → Reque Ancieta (EEA-19, Bs.804, original 23-abr) — PK ya ocupada en SGA
                2559 => ['120241273', '220231017'],

                // 2560 → Anarata Pirapi (EEA-19, Nivelacion.Abril, Bs.160, copia manual 28-abr)
                //      → Choque Nogales (EEA-19, Bs.757, original 23-abr) — PK ya ocupada en SGA
                2560 => ['220231032', '120251040'],

                // 2561 → Guaman Terceros (EEA-19, Mens.Mayo+Junio, Bs.1600, copia manual 28-abr)
                2561 => ['120241027'],
            ],
        ],

        // ── MEA (Mecánica) — sga_mec ────────────────────────────────────────
        // Facturas generadas en MEA-220526 vía ServiciosOnline (pago en línea
        // BANCO BISA) entre 2026-04-23 y 2026-04-27. Mismo problema: copias
        // manuales en eco_backup de registros que ya existen en el SGA con CUF.
        // NOTA: los mismos nros (1421-1425) tienen registros legítimos en sga_elec
        //       para otros estudiantes (Rodriguez, Marcani, Merino, Vera, Bacilio)
        //       con pensum EEA-* → esos NO están aquí y se migran normalmente.
        'sga_mec' => [
            2026 => [
                // 1421 → Saravia Nogales (04-MTZ-23, Bs.3, VIGENTE, CUF vacío)
                1421 => ['120240155'],

                // 1422 → Choque Foronda (04-MTZ-23, Bs.640, VIGENTE, CUF vacío)
                1422 => ['120240204'],

                // 1423 → Quispe Llanos (04-MTZ-23, Mens.Abril, Bs.800, VIGENTE, CUF vacío)
                1423 => ['220240012'],

                // 1424 → Rocabado Nuñez (04-MTZ-23, Mens.Abril, Bs.808, VIGENTE, CUF vacío)
                1424 => ['120240235'],

                // 1425 → Espinoza Colque (04-MTZ-23, Mens.Abril, Bs.812, VALIDADA)
                1425 => ['220250008'],
            ],
        ],

    ],

];
