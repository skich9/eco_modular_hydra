<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Evitar dependencia de doctrine/dbal usando SQL crudo
        try {
            DB::statement('ALTER TABLE qr_conceptos_detalle MODIFY concepto VARCHAR(255)');
        } catch (\Throwable $e) {
            // Ignorar si ya está en 255
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE qr_conceptos_detalle MODIFY concepto VARCHAR(50)');
        } catch (\Throwable $e) {
            // No-op si no es posible revertir
        }
    }
};
