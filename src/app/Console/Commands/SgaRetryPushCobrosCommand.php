<?php

namespace App\Console\Commands;

use App\Models\SgaPushCobro;
use App\Services\Sga\SgaPushService;
use Illuminate\Console\Command;

class SgaRetryPushCobrosCommand extends Command
{
    protected $signature = 'sga:retry-push-cobros
                            {--conn=all : Conexión destino (all|sga_elec|sga_mec)}
                            {--limit=100 : Máximo de registros a reintentar}';

    protected $description = 'Reintenta la sincronización de cobros (mensualidad/arrastre) pendientes hacia el SGA vía API';

    public function handle(SgaPushService $pushService): int
    {
        $conn  = $this->option('conn');
        $limit = (int) $this->option('limit');

        $query = SgaPushCobro::pendientes()->where('destino_tabla', 'pago');

        if ($conn !== 'all') {
            $query->porConexion($conn);
        }

        $pendientes = $query->limit($limit)->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay cobros pendientes de sincronización.');
            return self::SUCCESS;
        }

        $this->info("Reintentando {$pendientes->count()} cobro(s) pendiente(s)...");

        $ok     = 0;
        $fallos = 0;

        foreach ($pendientes as $registro) {
            $resultado = $pushService->enviarAlSga(
                $registro,
                $registro->destino_conn,
                '/api/sync/pago',
                $registro->payload
            );

            if ($resultado) {
                $ok++;
                $this->line(" <info>OK</info>  {$registro->cobro_uid}");
            } else {
                $fallos++;
                $this->line(" <comment>FAIL</comment> {$registro->cobro_uid} — {$registro->ultimo_error}");
            }
        }

        $this->newLine();
        $this->info("Resultado: {$ok} sincronizados, {$fallos} fallidos.");

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
