<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepcion_ingresos', function (Blueprint $table) {
            $table->date('fecha_inicial_libros')->nullable()->after('fecha_recepcion');
            $table->date('fecha_final_libros')->nullable()->after('fecha_inicial_libros');
        });
    }

    public function down(): void
    {
        Schema::table('recepcion_ingresos', function (Blueprint $table) {
            $table->dropColumn(['fecha_inicial_libros', 'fecha_final_libros']);
        });
    }
};
