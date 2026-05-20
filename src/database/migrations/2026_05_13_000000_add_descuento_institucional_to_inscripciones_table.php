<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscripciones', function (Blueprint $table) {
            if (!Schema::hasColumn('inscripciones', 'descuento_institucional')) {
                $table->boolean('descuento_institucional')->nullable()->default(null)->after('source_cod_inscrip');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inscripciones', function (Blueprint $table) {
            if (Schema::hasColumn('inscripciones', 'descuento_institucional')) {
                $table->dropColumn('descuento_institucional');
            }
        });
    }
};
