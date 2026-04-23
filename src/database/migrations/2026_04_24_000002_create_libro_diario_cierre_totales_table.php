<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Totales del Libro Diario por cierre (misma regla que LibroDiarioAggregatorService),
     * almacenados al cerrar caja o al primer uso en el reporte de recepción.
     */
    public function up(): void
    {
        if (Schema::hasTable('libro_diario_cierre_totales')) {
            return;
        }

        if (!Schema::hasTable('libro_diario_cierre')) {
            return;
        }

        Schema::create('libro_diario_cierre_totales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_libro_diario_cierre');
            $table->decimal('total_deposito', 16, 2)->default(0);
            $table->decimal('total_traspaso', 16, 2)->default(0);
            $table->decimal('total_recibos', 16, 2)->default(0);
            $table->decimal('total_facturas', 16, 2)->default(0);
            $table->decimal('total_entregado', 16, 2)->default(0);
            $table->timestamps();

            $table->unique('id_libro_diario_cierre', 'ldct_cierre_unique');
            $table->foreign('id_libro_diario_cierre', 'fk_ldct_cierre')
                ->references('id')
                ->on('libro_diario_cierre')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libro_diario_cierre_totales');
    }
};
