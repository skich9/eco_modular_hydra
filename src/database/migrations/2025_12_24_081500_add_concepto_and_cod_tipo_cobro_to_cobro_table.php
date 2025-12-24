<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            $table->text('concepto')->nullable()->after('observaciones');
            $table->string('cod_tipo_cobro', 50)->nullable()->after('concepto');
            
            // Adding foreign key for cod_tipo_cobro (will be added after tipo_cobro table is created)
            // $table->foreign('cod_tipo_cobro')->references('cod_tipo_cobro')->on('tipo_cobro')->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            $table->dropColumn(['concepto', 'cod_tipo_cobro']);
        });
    }
};
