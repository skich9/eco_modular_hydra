<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('qr_conceptos_detalle')) {
            return;
        }

        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            // Agregar tipo_documento (varchar 10) después de monto_saldo
            if (!Schema::hasColumn('qr_conceptos_detalle', 'tipo_documento')) {
                $table->string('tipo_documento', 10)->nullable()->after('monto_saldo');
            }
            
            // Agregar medio_doc (varchar 10) después de tipo_documento
            if (!Schema::hasColumn('qr_conceptos_detalle', 'medio_doc')) {
                $table->string('medio_doc', 10)->nullable()->after('tipo_documento');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('qr_conceptos_detalle')) {
            return;
        }

        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            if (Schema::hasColumn('qr_conceptos_detalle', 'tipo_documento')) {
                $table->dropColumn('tipo_documento');
            }
            
            if (Schema::hasColumn('qr_conceptos_detalle', 'medio_doc')) {
                $table->dropColumn('medio_doc');
            }
        });
    }
};
