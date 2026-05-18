<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterQrConceptosDetalleAddIdItem extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('qr_conceptos_detalle')) {
            return;
        }

        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            if (!Schema::hasColumn('qr_conceptos_detalle', 'id_item')) {
                $table->integer('id_item')->nullable()->after('id_cuota');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('qr_conceptos_detalle')) {
            return;
        }

        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            if (Schema::hasColumn('qr_conceptos_detalle', 'id_item')) {
                $table->dropColumn('id_item');
            }
        });
    }
}
