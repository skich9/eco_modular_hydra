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
        Schema::create('estudiantes', function (Blueprint $table) {
            $table->unsignedBigInteger('cod_ceta')->primary(); // Cambiado a unsignedBigInteger
            $table->string('ci', 255);
            $table->string('nombres', 255);
            $table->string('ap_paterno', 255);
            $table->string('ap_materno', 255);
            $table->string('email', 255)->nullable();
            $table->string('cod_pensum', 255);
            $table->string('estado', 255)->nullable();
            $table->timestamps();

            $table->foreign('cod_pensum')
                  ->references('cod_pensum')
                  ->on('pensums')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estudiantes');
    }
};
