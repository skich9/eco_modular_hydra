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
        Schema::create('sin_sucursal_pensum', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->integer('codigo_sucursal');
            $table->string('cod_pensum');

            $table->unique(['codigo_sucursal', 'cod_pensum'], 'uq_codigo_sucursal_cod_pensum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sin_sucursal_pensum');
    }
};
