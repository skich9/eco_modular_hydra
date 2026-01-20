<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            if (!Schema::hasColumn('cobro', 'concepto')) {
                $table->text('concepto')->nullable()->after('observaciones');
            }
            if (!Schema::hasColumn('cobro', 'cod_tipo_cobro')) {
                $table->string('cod_tipo_cobro', 50)->nullable()->after('concepto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            $table->dropColumn(['concepto', 'cod_tipo_cobro']);
        });
    }
};
