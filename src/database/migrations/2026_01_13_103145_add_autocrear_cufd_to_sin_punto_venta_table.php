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
        Schema::table('sin_punto_venta', function (Blueprint $table) {
            //
            $table->boolean('autocrear_cufd')->default(false);
            $table->integer('codigo_ambiente')->default(2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sin_punto_venta', function (Blueprint $table) {
            //
            $table->dropColumn('autocrear_cufd');
            $table->dropColumn('codigo_ambiente');
        });
    }
};
