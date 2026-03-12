<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CuentaBancaria;

class SyncCuentasBancarias extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cuentas-bancarias 
                            {sga=elec : Conexión SGA a usar (elec o mec)}
                            {--dry-run : Ejecutar sin guardar cambios}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Sincroniza cuentas bancarias desde la tabla bancos del SGA';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $sgaArg = strtolower($this->argument('sga'));
        
        // Determinar conexión SGA
        $sgaConnection = match($sgaArg) {
            'elec', 'electronica' => 'sga_elec',
            'mec', 'mecanica' => 'sga_mec',
            default => null,
        };
        
        if (!$sgaConnection) {
            $this->error("Conexión SGA inválida: '{$sgaArg}'. Use 'elec' o 'mec'");
            return 1;
        }
        
        if ($dryRun) {
            $this->info('=== MODO DRY-RUN: No se guardarán cambios ===');
        }
        
        $this->info("Iniciando sincronización de cuentas bancarias desde SGA ({$sgaArg})...");
        
        try {
            // Verificar que exista la conexión
            if (!config("database.connections.{$sgaConnection}")) {
                $this->error("Conexión SGA '{$sgaConnection}' no configurada en database.php");
                return 1;
            }
            
            // Obtener bancos del SGA
            $bancosSga = DB::connection($sgaConnection)
                ->table('bancos')
                ->select('tipo_banco', 'nombre', 'num_cuenta', 'moneda', 'activo')
                ->get();
            
            if ($bancosSga->isEmpty()) {
                $this->warn('No se encontraron bancos en el SGA');
                return 0;
            }
            
            $this->info("Encontrados {$bancosSga->count()} bancos en el SGA");
            
            $created = 0;
            $updated = 0;
            $skipped = 0;
            
            foreach ($bancosSga as $bancoSga) {
                $numeroCuenta = trim((string)$bancoSga->num_cuenta);
                $banco = trim((string)$bancoSga->nombre);
                $moneda = trim((string)($bancoSga->moneda ?? 'Bolivianos'));
                $activo = (bool)($bancoSga->activo ?? true);
                
                // Validar que tenga número de cuenta
                if (empty($numeroCuenta)) {
                    $this->warn("Saltando banco sin número de cuenta: {$banco}");
                    $skipped++;
                    continue;
                }
                
                // Buscar si ya existe la cuenta bancaria por número de cuenta
                $cuentaExistente = CuentaBancaria::where('numero_cuenta', $numeroCuenta)->first();
                
                if ($cuentaExistente) {
                    // Actualizar campos desde SGA
                    $cambios = [];
                    
                    if ($cuentaExistente->banco !== $banco) {
                        $cambios['banco'] = $banco;
                    }
                    if ($cuentaExistente->moneda !== $moneda) {
                        $cambios['moneda'] = $moneda;
                    }
                    if ($cuentaExistente->activo !== $activo) {
                        $cambios['activo'] = $activo;
                    }
                    
                    if (!empty($cambios)) {
                        $this->info("Actualizando cuenta {$numeroCuenta}: " . json_encode($cambios));
                        
                        if (!$dryRun) {
                            $cuentaExistente->update($cambios);
                        }
                        $updated++;
                    } else {
                        $this->line("Cuenta {$numeroCuenta} ya está sincronizada");
                        $skipped++;
                    }
                } else {
                    // Crear nueva cuenta bancaria
                    $nuevaCuenta = [
                        'banco' => $banco,
                        'numero_cuenta' => $numeroCuenta,
                        'tipo_cuenta' => 'CAJA DE AHORRO', // Valor por defecto para nuevas inserciones
                        'titular' => 'xxxxx', // Valor por defecto para nuevas inserciones
                        'habilitado_QR' => false,
                        'I_R' => 0, // 0 para nuevas inserciones
                        'estado' => true,
                        'activo' => $activo,
                        'moneda' => $moneda,
                    ];
                    
                    $this->info("Creando nueva cuenta: {$numeroCuenta} - {$banco} ({$moneda})");
                    
                    if (!$dryRun) {
                        CuentaBancaria::create($nuevaCuenta);
                    }
                    $created++;
                }
            }
            
            $this->newLine();
            $this->info('=== RESUMEN DE SINCRONIZACIÓN ===');
            $this->info("Cuentas creadas: {$created}");
            $this->info("Cuentas actualizadas: {$updated}");
            $this->info("Cuentas sin cambios: {$skipped}");
            
            if ($dryRun) {
                $this->warn('Modo DRY-RUN: No se guardaron cambios en la base de datos');
                $this->info('Ejecuta sin --dry-run para aplicar los cambios');
            } else {
                $this->info('✓ Sincronización completada exitosamente');
            }
            
            return 0;
            
        } catch (\Throwable $e) {
            $this->error('Error durante la sincronización: ' . $e->getMessage());
            Log::error('SyncCuentasBancarias error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
