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
        Schema::table('pensums', function (Blueprint $table) {
            // Añadir restricción de clave foránea para codigo_carrera
            $table->foreign('codigo_carrera')
                  ->references('codigo_carrera')
                  ->on('carrera')
                  ->onDelete('restrict')
                  ->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pensums', function (Blueprint $table) {
            // Eliminar la clave foránea
            $table->dropForeign(['codigo_carrera']);
        });
    }
};
