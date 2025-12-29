<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReposicionFacturaToCobroTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        if (Schema::hasTable('cobro')) {
            Schema::table('cobro', function (Blueprint $table) {
                if (!Schema::hasColumn('cobro', 'reposicion_factura')) {
                    $table->boolean('reposicion_factura')->nullable()->after('concepto');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        if (Schema::hasTable('cobro')) {
            Schema::table('cobro', function (Blueprint $table) {
                if (Schema::hasColumn('cobro', 'reposicion_factura')) {
                    $table->dropColumn('reposicion_factura');
                }
            });
        }
    }
}
