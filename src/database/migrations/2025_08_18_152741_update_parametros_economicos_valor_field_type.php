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
            // Cambiar valor a varchar(255) como solicitado
            $table->string('valor', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parametros_economicos', function (Blueprint $table) {
            // Restaurar a decimal
            $table->decimal('valor', 10, 2)->change();
        });
    }
};
