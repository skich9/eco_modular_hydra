<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el valor 'excluded' al ENUM status de sga_migration_log.
 *
 * 'excluded' identifica registros descartados explícitamente por la lista de
 * exclusión en config/sga_migration.php (copias manuales / duplicados conocidos),
 * diferenciándolos de los 'skipped' por colisión de PK detectada en tiempo real.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysql')->statement("
            ALTER TABLE sga_migration_log
            MODIFY COLUMN status ENUM('inserted','skipped','excluded','error') NOT NULL
        ");
    }

    public function down(): void
    {
        // Convertir 'excluded' → 'skipped' antes de eliminar el valor del ENUM
        DB::connection('mysql')->table('sga_migration_log')
            ->where('status', 'excluded')
            ->update(['status' => 'skipped']);

        DB::connection('mysql')->statement("
            ALTER TABLE sga_migration_log
            MODIFY COLUMN status ENUM('inserted','skipped','error') NOT NULL
        ");
    }
};
