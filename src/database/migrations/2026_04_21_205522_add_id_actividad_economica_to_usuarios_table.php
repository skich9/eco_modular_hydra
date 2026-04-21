<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('usuarios', 'id_actividad_economica')) {
                $table->unsignedBigInteger('id_actividad_economica')->nullable();
                
                // Foreign key (optional but good practice)
                $table->foreign('id_actividad_economica', 'fk_usuario_actividad_eco')
                      ->references('id_actividad_economica')
                      ->on('actividades_economicas')
                      ->onDelete('set null');
            }
        });
    }

    public function down()
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'id_actividad_economica')) {
                $table->dropForeign('fk_usuario_actividad_eco');
                $table->dropColumn('id_actividad_economica');
            }
        });
    }
};
