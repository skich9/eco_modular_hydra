<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== VERIFICACIÓN DE ASIGNACIONES ===\n";

$sinNumero = DB::table('asignacion_costos')->whereNull('numero_cuota')->count();
$conNumero = DB::table('asignacion_costos')->whereNotNull('numero_cuota')->count();

echo "Asignaciones SIN numero_cuota: {$sinNumero}\n";
echo "Asignaciones CON numero_cuota: {$conNumero}\n";

if ($sinNumero > 0) {
    echo "\n¿Eliminar las {$sinNumero} asignaciones sin numero_cuota? (s/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) === 's') {
        $deleted = DB::table('asignacion_costos')->whereNull('numero_cuota')->delete();
        echo "✓ Eliminadas {$deleted} asignaciones antiguas\n";
        echo "Ahora ejecuta: php artisan db:seed --class=AsignacionCostosSeeder\n";
    } else {
        echo "Operación cancelada\n";
    }
    fclose($handle);
}
