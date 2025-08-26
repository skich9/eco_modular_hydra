<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('descuentos', function (Blueprint $table) {
            $table->unsignedBigInteger('cod_ceta');
            $table->string('cod_pensum', 50);
            $table->unsignedBigInteger('cod_inscrip');
            $table->unsignedBigInteger('id_usuario');
            $table->bigIncrements('id_descuentos');
            $table->string('nombre', 255);
            $table->text('observaciones')->nullable();
            $table->decimal('porcentaje', 10, 2);
            $table->string('tipo', 100)->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('cod_ceta')->references('cod_ceta')->on('estudiantes')->onDelete('restrict');
            $table->foreign('cod_pensum')->references('cod_pensum')->on('pensums')->onDelete('restrict');
            $table->foreign('cod_inscrip')->references('cod_inscrip')->on('inscripciones')->onDelete('restrict');
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('descuentos');
    }
};