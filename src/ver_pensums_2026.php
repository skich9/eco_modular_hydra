<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PENSUMS EN INSCRIPCIONES 2026 ===\n\n";

$pensums2026 = DB::table('inscripciones')
    ->select('cod_pensum', DB::raw('COUNT(*) as total'))
    ->where('gestion', '>=', '1/2026')
    ->groupBy('cod_pensum')
    ->orderBy('total', 'desc')
    ->get();

echo "Pensums en inscripciones de gestión 2026:\n";
foreach ($pensums2026 as $p) {
    echo "  {$p->cod_pensum}: {$p->total} inscripciones\n";
}

echo "\n=== PENSUMS EN COSTOS ===\n\n";

$pensumsCostos = DB::table('costo_semestral')
    ->select('cod_pensum', 'gestion', DB::raw('COUNT(*) as total'))
    ->groupBy('cod_pensum', 'gestion')
    ->orderBy('cod_pensum')
    ->get();

echo "Pensums en costos semestrales:\n";
foreach ($pensumsCostos as $p) {
    echo "  {$p->cod_pensum} (gestión {$p->gestion}): {$p->total} costos\n";
}

echo "\n=== MUESTRA DE INSCRIPCIONES 2026 ===\n\n";

$muestra = DB::table('inscripciones')
    ->select('cod_inscrip', 'cod_pensum', 'gestion', 'cod_curso', 'tipo_inscripcion')
    ->where('gestion', '1/2026')
    ->limit(10)
    ->get();

foreach ($muestra as $ins) {
    echo "cod_inscrip: {$ins->cod_inscrip}\n";
    echo "  cod_pensum: {$ins->cod_pensum}\n";
    echo "  gestion: {$ins->gestion}\n";
    echo "  cod_curso: {$ins->cod_curso}\n";
    echo "  tipo_inscripcion: {$ins->tipo_inscripcion}\n";
    
    // Intentar buscar costo para este pensum
    $costos = DB::table('costo_semestral')
        ->where('cod_pensum', $ins->cod_pensum)
        ->where('gestion', $ins->gestion)
        ->count();
    
    echo "  Costos encontrados (match exacto): {$costos}\n";
    
    // Buscar con LIKE
    $costosLike = DB::table('costo_semestral')
        ->where('cod_pensum', 'LIKE', $ins->cod_pensum . '%')
        ->where('gestion', $ins->gestion)
        ->count();
    
    echo "  Costos encontrados (LIKE): {$costosLike}\n";
    echo "  ---\n";
}
