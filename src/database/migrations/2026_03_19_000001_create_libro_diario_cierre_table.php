<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla para registrar cierres de caja del Libro Diario.
     * Almacena orden_cierre por usuario y fecha para generar el código RD-{carrera}-{mes}-{orden}.
     */
    public function up(): void
    {
        Schema::create('libro_diario_cierre', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('id_usuario');
            $table->date('fecha');
            $table->unsignedInteger('orden_cierre')->default(1);
            $table->string('codigo_carrera', 50)->nullable();
            $table->time('hora_cierre')->nullable();
            $table->timestamps();

            $table->index(['id_usuario', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libro_diario_cierre');
    }
};
