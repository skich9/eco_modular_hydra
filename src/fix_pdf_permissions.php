<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\File;

echo "=== VERIFICACIÓN Y CORRECCIÓN DE PERMISOS PARA PDFs ===\n\n";

$directories = [
    'storage/siat_xml',
    'storage/siat_xml/facturas',
    'storage/app/recibos',
    'storage/logs',
];

foreach ($directories as $dir) {
    $fullPath = base_path($dir);
    
    if (!File::exists($fullPath)) {
        echo "Creando directorio: {$dir}\n";
        File::makeDirectory($fullPath, 0775, true);
    }
    
    $writable = is_writable($fullPath);
    $readable = is_readable($fullPath);
    
    echo "Directorio: {$dir}\n";
    echo "  - Existe: " . (File::exists($fullPath) ? 'SÍ' : 'NO') . "\n";
    echo "  - Writable: " . ($writable ? 'SÍ' : 'NO') . "\n";
    echo "  - Readable: " . ($readable ? 'SÍ' : 'NO') . "\n";
    
    if (!$writable) {
        echo "  ⚠ ADVERTENCIA: El directorio no tiene permisos de escritura\n";
        echo "  Ejecuta: chmod -R 775 {$dir}\n";
    }
    echo "\n";
}

// Verificar dompdf
echo "=== VERIFICACIÓN DE DOMPDF ===\n";
if (class_exists('Dompdf\Dompdf')) {
    echo "✓ Dompdf está instalado\n";
    $dompdf = new \Dompdf\Dompdf();
    echo "✓ Dompdf puede instanciarse correctamente\n";
} else {
    echo "✗ Dompdf NO está instalado\n";
    echo "  Ejecuta: composer require dompdf/dompdf\n";
}

echo "\n=== PRUEBA DE GENERACIÓN DE PDF ===\n";
try {
    $dompdf = new \Dompdf\Dompdf();
    $html = '<html><body><h1>Test PDF</h1><p>Este es un PDF de prueba.</p></body></html>';
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    
    $testPath = storage_path('app/test_pdf.pdf');
    file_put_contents($testPath, $dompdf->output());
    
    if (file_exists($testPath)) {
        $size = filesize($testPath);
        echo "✓ PDF de prueba generado exitosamente\n";
        echo "  Ubicación: {$testPath}\n";
        echo "  Tamaño: {$size} bytes\n";
        unlink($testPath);
        echo "  (archivo de prueba eliminado)\n";
    } else {
        echo "✗ No se pudo crear el archivo PDF de prueba\n";
    }
} catch (\Throwable $e) {
    echo "✗ Error al generar PDF de prueba: " . $e->getMessage() . "\n";
}

echo "\n=== RESUMEN ===\n";
echo "Si ves errores de permisos, ejecuta desde la raíz del proyecto:\n";
echo "  docker exec angular_laravel_php chown -R www-data:www-data storage\n";
echo "  docker exec angular_laravel_php chmod -R 775 storage\n";
