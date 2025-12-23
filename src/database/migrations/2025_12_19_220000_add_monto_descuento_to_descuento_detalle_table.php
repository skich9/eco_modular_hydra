<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMontoDescuentoToDescuentoDetalleTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('descuento_detalle') && !Schema::hasColumn('descuento_detalle', 'monto_descuento')) {
            Schema::table('descuento_detalle', function (Blueprint $table) {
                $table->decimal('monto_descuento', 10, 2)->nullable()->after('id_cuota');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('descuento_detalle') && Schema::hasColumn('descuento_detalle', 'monto_descuento')) {
            Schema::table('descuento_detalle', function (Blueprint $table) {
                $table->dropColumn('monto_descuento');
            });
        }
    }
}
