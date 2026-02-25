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
        Schema::table('asignacion_mora', function (Blueprint $table) {
            $table->unsignedBigInteger('id_asignacion_vinculada')->nullable()->after('id_asignacion_costo');
            $table->foreign('id_asignacion_vinculada')
                ->references('id_asignacion_costo')
                ->on('asignacion_costos')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asignacion_mora', function (Blueprint $table) {
            $table->dropForeign(['id_asignacion_vinculada']);
            $table->dropColumn('id_asignacion_vinculada');
        });
    }
};
