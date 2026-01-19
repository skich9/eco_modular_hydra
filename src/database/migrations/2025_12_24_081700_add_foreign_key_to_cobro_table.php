<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tipo_cobro')) {
            return;
        }

        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        $foreignKeys = $sm->listTableForeignKeys('cobro');

        $hasFk = false;
        foreach ($foreignKeys as $fk) {
            if (in_array('cod_tipo_cobro', $fk->getLocalColumns())) {
                $hasFk = true;
                break;
            }
        }

        if (!$hasFk) {
            Schema::table('cobro', function (Blueprint $table) {
                $table->foreign('cod_tipo_cobro')
                      ->references('cod_tipo_cobro')
                      ->on('tipo_cobro')
                      ->onDelete('restrict')
                      ->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cobro', function (Blueprint $table) {
            $table->dropForeign(['cod_tipo_cobro']);
        });
    }
};
