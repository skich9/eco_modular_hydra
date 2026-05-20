<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepcion_ingresos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_caja_actividad')->nullable()->after('id_actividad_economica');
            $table->foreign('id_caja_actividad')->references('id_caja_actividad')->on('cajas_actividad')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recepcion_ingresos', function (Blueprint $table) {
            $table->dropForeign(['id_caja_actividad']);
            $table->dropColumn('id_caja_actividad');
        });
    }
};
