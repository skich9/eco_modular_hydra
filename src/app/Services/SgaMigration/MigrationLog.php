<?php

namespace App\Services\SgaMigration;

use Illuminate\Support\Facades\DB;

/**
 * Registro de idempotencia. Consulta y escribe en sga_migration_log (MySQL de la app).
 */
class MigrationLog
{
    /** ¿Ya fue procesado (inserted o skipped) en una corrida anterior? */
    public function alreadyDone(string $sourceTable, string $sourcePk, string $destConn): bool
    {
        return DB::table('sga_migration_log')
            ->where('source_table', $sourceTable)
            ->where('source_pk', $sourcePk)
            ->where('dest_conn', $destConn)
            ->whereIn('status', ['inserted', 'skipped'])
            ->exists();
    }

    public function write(
        string $sourceTable,
        string $sourcePk,
        string $destConn,
        string $destTable,
        ?string $destPk,
        string $status,
        ?string $errorMessage = null
    ): void {
        DB::table('sga_migration_log')->insert([
            'source_table'  => $sourceTable,
            'source_pk'     => $sourcePk,
            'dest_conn'     => $destConn,
            'dest_table'    => $destTable,
            'dest_pk'       => $destPk,
            'status'        => $status,
            'error_message' => $errorMessage,
            'pushed_at'     => now(),
        ]);
    }
}
