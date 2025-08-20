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
        Schema::table('materia', function (Blueprint $table) {
            // Cambiar descripción a tipo TEXT como en el SQL original
            $table->text('descripcion')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materia', function (Blueprint $table) {
            // Restaurar a los tipos anteriores
            $table->string('descripcion', 50)->nullable()->change();
        });
    }
};
