<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nota_bancaria', function (Blueprint $table) {
            $table->integer('nro_cobro')->nullable()->after('nro_transaccion');
            $table->index('nro_cobro');
        });
    }

    public function down(): void
    {
        Schema::table('nota_bancaria', function (Blueprint $table) {
            $table->dropIndex(['nro_cobro']);
            $table->dropColumn('nro_cobro');
        });
    }
};
