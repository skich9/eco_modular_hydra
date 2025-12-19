<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBiographicalFieldsToEstudiantesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('estudiantes', function (Blueprint $table) {
            $table->string('carrera', 255)->nullable()->after('email');
            $table->string('resolucion', 255)->nullable()->after('carrera');
            $table->string('gestion', 255)->nullable()->after('resolucion');
            $table->string('grupos', 255)->nullable()->after('gestion');
            $table->string('descuento', 255)->nullable()->after('grupos');
            $table->text('observaciones')->nullable()->after('descuento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('estudiantes', function (Blueprint $table) {
            $table->dropColumn([
                'carrera',
                'resolucion', 
                'gestion',
                'grupos',
                'descuento',
                'observaciones'
            ]);
        });
    }
}
