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
        Schema::create('pensums', function (Blueprint $table) {
            $table->string('cod_pensum', 50)->primary();
            $table->string('codigo_carrera', 50);
            $table->string('nombre', 40);
            $table->string('descripcion', 50)->nullable();
            $table->integer('cantidad_semestres')->nullable();
            $table->integer('orden')->nullable();
            $table->string('nivel', 50)->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pensums');
    }
};
