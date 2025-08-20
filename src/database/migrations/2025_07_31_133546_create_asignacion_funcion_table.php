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
       Schema::create('asignacion_funcion', function (Blueprint $table) {
            $table->foreignId('id_funcion')->constrained('funciones', 'id_funcion');
            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario');
            $table->date('fecha_ini');
            $table->date('fecha_fin')->nullable();
            $table->boolean('usuario_asig')->nullable();
            $table->timestamps();
            
            $table->primary(['id_funcion', 'id_usuario']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asignacion_funcion');
    }
};
