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
        if (!Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            // Agregar campo activo (boolean/tinyint) - equivalente a 'activo' del SGA
            if (!Schema::hasColumn('cuentas_bancarias', 'activo')) {
                $table->boolean('activo')->default(true)->after('estado');
            }
            
            // Agregar campo moneda (varchar 20) - equivalente a 'moneda' del SGA
            if (!Schema::hasColumn('cuentas_bancarias', 'moneda')) {
                $table->string('moneda', 20)->default('Bolivianos')->after('activo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            if (Schema::hasColumn('cuentas_bancarias', 'activo')) {
                $table->dropColumn('activo');
            }
            
            if (Schema::hasColumn('cuentas_bancarias', 'moneda')) {
                $table->dropColumn('moneda');
            }
        });
    }
};
