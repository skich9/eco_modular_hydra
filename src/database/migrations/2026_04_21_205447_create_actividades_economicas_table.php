<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('actividades_economicas', function (Blueprint $table) {
            $table->id('id_actividad_economica');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->string('prefijo', 20)->nullable();
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('actividades_economicas');
    }
};
