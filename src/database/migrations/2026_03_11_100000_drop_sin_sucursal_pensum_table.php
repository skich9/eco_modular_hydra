<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sin_sucursal_pensum');
    }

    public function down(): void
    {
        Schema::create('sin_sucursal_pensum', function (Blueprint $table) {
            $table->id();
            $table->integer('codigo_sucursal');
            $table->string('cod_pensum', 50);
            $table->timestamps();
        });
    }
};
