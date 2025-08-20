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
        Schema::create('rol', function (Blueprint $table) {
            $table->id('id_rol'); // Equivalente a bigint AUTO_INCREMENT
            $table->string('nombre', 60);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('estado')->nullable()->default(true);
            $table->timestamps(); // Crea created_at y updated_at
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rol');
    }
};
