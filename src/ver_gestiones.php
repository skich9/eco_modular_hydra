<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== GESTIONES EN INSCRIPCIONES ===\n\n";

$gestiones = DB::table('inscripciones')
    ->select('gestion')
    ->distinct()
    ->orderBy('gestion')
    ->get();

echo "Total gestiones únicas: " . $gestiones->count() . "\n\n";

foreach ($gestiones as $g) {
    $count = DB::table('inscripciones')->where('gestion', $g->gestion)->count();
    echo "{$g->gestion}: {$count} inscripciones\n";
}

echo "\n=== GESTIONES EN COSTOS SEMESTRALES ===\n\n";

$gestionesCostos = DB::table('costo_semestral')
    ->select('gestion')
    ->distinct()
    ->orderBy('gestion')
    ->get();

echo "Total gestiones únicas: " . $gestionesCostos->count() . "\n\n";

foreach ($gestionesCostos as $g) {
    $count = DB::table('costo_semestral')->where('gestion', $g->gestion)->count();
    echo "{$g->gestion}: {$count} costos\n";
}

echo "\n=== PENSUMS EN INSCRIPCIONES ===\n\n";

$pensums = DB::table('inscripciones')
    ->select('cod_pensum')
    ->distinct()
    ->orderBy('cod_pensum')
    ->limit(20)
    ->get();

echo "Primeros 20 pensums:\n";
foreach ($pensums as $p) {
    $count = DB::table('inscripciones')->where('cod_pensum', $p->cod_pensum)->count();
    echo "{$p->cod_pensum}: {$count} inscripciones\n";
}

echo "\n=== PENSUMS EN COSTOS ===\n\n";

$pensumsCostos = DB::table('costo_semestral')
    ->select('cod_pensum')
    ->distinct()
    ->orderBy('cod_pensum')
    ->get();

echo "Todos los pensums con costos:\n";
foreach ($pensumsCostos as $p) {
    $count = DB::table('costo_semestral')->where('cod_pensum', $p->cod_pensum)->count();
    echo "{$p->cod_pensum}: {$count} costos\n";
}
