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
        Schema::table('pensums', function (Blueprint $table) {
            if (!Schema::hasColumn('pensums', 'resolucion')) {
                $table->string('resolucion')->nullable()->after('descripcion');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pensums', function (Blueprint $table) {
            if (Schema::hasColumn('pensums', 'resolucion')) {
                $table->dropColumn('resolucion');
            }
        });
    }
};
