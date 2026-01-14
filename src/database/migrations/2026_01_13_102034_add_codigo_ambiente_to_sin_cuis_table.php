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
        Schema::table('sin_cuis', function (Blueprint $table) {
            // Add 'codigo_ambiente' column after 'codigo_sucursal'
            $table->integer('codigo_ambiente')->default(2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sin_cuis', function (Blueprint $table) {
            // Remove 'codigo_ambiente' column
            $table->dropColumn('codigo_ambiente');
        });
    }
};
