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
        Schema::create('parametros_generales', function (Blueprint $table) {
            $table->bigIncrements('id_parametros_generales');
            $table->string('nombre', 150);
            $table->string('valor', 255)->nullable();
            $table->boolean('estado');
            $table->timestamps();
            
            // Índices
            $table->index('nombre');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametros_generales');
    }
};
