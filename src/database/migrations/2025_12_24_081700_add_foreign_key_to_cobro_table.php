<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tipo_cobro')) {
            return;
        }

        // Laravel 11 no soporta verificación de foreign keys de forma nativa
        // Usamos try-catch para evitar errores si la FK ya existe
        try {
            Schema::table('cobro', function (Blueprint $table) {
                $table->foreign('cod_tipo_cobro')
                      ->references('cod_tipo_cobro')
                      ->on('tipo_cobro')
                      ->onDelete('restrict')
                      ->onUpdate('restrict');
            });
        } catch (\Exception $e) {
            // Foreign key ya existe, continuar
        }
    }

    public function down(): void
    {
        try {
            Schema::table('cobro', function (Blueprint $table) {
                $table->dropForeign(['cod_tipo_cobro']);
            });
        } catch (\Exception $e) {
            // Foreign key no existe, continuar
        }
    }
};
