<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sga_push_cobros', function (Blueprint $table) {
            $table->dropColumn('anio_cobro');
            $table->string('nro_cobro', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sga_push_cobros', function (Blueprint $table) {
            $table->integer('anio_cobro')->nullable()->after('nro_cobro');
            $table->integer('nro_cobro')->nullable()->change();
        });
    }
};
