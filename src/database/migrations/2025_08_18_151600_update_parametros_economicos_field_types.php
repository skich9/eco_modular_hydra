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
        Schema::table('parametros_economicos', function (Blueprint $table) {
            // Cambiar descripción a varchar(255) como en el SQL original
            $table->string('descripcion', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parametros_economicos', function (Blueprint $table) {
            // Restaurar a los tipos anteriores
            $table->string('descripcion', 50)->change();
        });
    }
};
