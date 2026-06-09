<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('asignacion_costos', 'fecha_vencimiento')) {
            Schema::table('asignacion_costos', function (Blueprint $table) {
                $table->date('fecha_vencimiento')->nullable()->change();
            });
        }

        $col = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento')
            ? 'fecha_vencimiento'
            : (Schema::hasColumn('parametros_cuota', 'fecha_venecimiento') ? 'fecha_venecimiento' : null);

        if ($col) {
            Schema::table('parametros_cuota', function (Blueprint $table) use ($col) {
                $table->date($col)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // No se revierten a NOT NULL para evitar fallos con datos existentes nulos
    }
};
