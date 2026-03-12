<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DIAGNÓSTICO DE MATCH INSCRIPCIONES-COSTOS ===\n\n";

// Muestra de inscripciones
echo "MUESTRA DE INSCRIPCIONES (primeras 5):\n";
$inscripciones = DB::table('inscripciones')
    ->select('cod_inscrip', 'cod_pensum', 'gestion', 'cod_curso', 'tipo_inscripcion')
    ->limit(5)
    ->get();

foreach ($inscripciones as $ins) {
    echo "  cod_inscrip: {$ins->cod_inscrip}\n";
    echo "  cod_pensum: {$ins->cod_pensum}\n";
    echo "  gestion: {$ins->gestion}\n";
    echo "  cod_curso: {$ins->cod_curso}\n";
    echo "  tipo_inscripcion: {$ins->tipo_inscripcion}\n";
    echo "  ---\n";
}

echo "\nMUESTRA DE COSTOS SEMESTRALES (primeros 10):\n";
$costos = DB::table('costo_semestral')
    ->select('id_costo_semestral', 'cod_pensum', 'gestion', 'semestre', 'turno', 'tipo_costo', 'monto_semestre')
    ->limit(10)
    ->get();

foreach ($costos as $costo) {
    echo "  ID: {$costo->id_costo_semestral}\n";
    echo "  cod_pensum: {$costo->cod_pensum}\n";
    echo "  gestion: {$costo->gestion}\n";
    echo "  semestre: {$costo->semestre}\n";
    echo "  turno: {$costo->turno}\n";
    echo "  tipo_costo: {$costo->tipo_costo}\n";
    echo "  monto: {$costo->monto_semestre}\n";
    echo "  ---\n";
}

// Intentar match con la primera inscripción
echo "\nINTENTO DE MATCH CON PRIMERA INSCRIPCIÓN:\n";
$ins = $inscripciones->first();
if ($ins) {
    echo "Buscando costo para:\n";
    echo "  cod_pensum: {$ins->cod_pensum}\n";
    echo "  gestion: {$ins->gestion}\n";
    
    // Extraer semestre y turno del cod_curso
    $segment = $ins->cod_curso;
    if (str_contains($ins->cod_curso, '-')) {
        $parts = explode('-', $ins->cod_curso);
        $segment = end($parts) ?: $ins->cod_curso;
    }
    $segment = trim($segment);
    $turnoChar = strtoupper(substr($segment, -1));
    $digits = preg_replace('/\D+/', '', $segment);
    $semestre = $digits !== '' ? intval(substr($digits, 0, 1)) : null;
    
    echo "  semestre extraído: " . ($semestre ?? 'null') . "\n";
    echo "  turnoChar extraído: {$turnoChar}\n";
    
    // Buscar costos que coincidan por pensum y gestion
    $costosPorPensum = DB::table('costo_semestral')
        ->where('cod_pensum', $ins->cod_pensum)
        ->where('gestion', $ins->gestion)
        ->get();
    
    echo "\nCostos encontrados para este pensum+gestion: " . $costosPorPensum->count() . "\n";
    foreach ($costosPorPensum as $c) {
        echo "  - semestre:{$c->semestre} turno:{$c->turno} tipo:{$c->tipo_costo}\n";
    }
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
