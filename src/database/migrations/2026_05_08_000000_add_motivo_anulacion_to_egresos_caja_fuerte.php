<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egresos_caja_fuerte', function (Blueprint $table) {
            $table->string('motivo_anulacion', 255)->nullable()->after('anular');
        });
    }

    public function down(): void
    {
        Schema::table('egresos_caja_fuerte', function (Blueprint $table) {
            $table->dropColumn('motivo_anulacion');
        });
    }
};
