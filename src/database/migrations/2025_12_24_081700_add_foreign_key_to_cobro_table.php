<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            // Adding foreign key for cod_tipo_cobro after tipo_cobro table is created
            $table->foreign('cod_tipo_cobro')
                  ->references('cod_tipo_cobro')
                  ->on('tipo_cobro')
                  ->onDelete('restrict')
                  ->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            $table->dropForeign(['cod_tipo_cobro']);
        });
    }
};
