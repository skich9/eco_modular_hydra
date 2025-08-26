<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('descuento_detalle', function (Blueprint $table) {
            $table->bigIncrements('id_descuento_detalle');
            $table->unsignedBigInteger('id_descuento');
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_inscripcion')->nullable();
            $table->unsignedBigInteger('id_cuota')->nullable();
            $table->string('cod_Archivo', 255)->nullable();
            $table->timestamp('fecha_registro');
            $table->date('fecha_solicitud');
            $table->text('observaciones')->nullable();
            $table->string('tipo_inscripcion', 100)->nullable();
            $table->string('turno', 100)->nullable();
            $table->string('semestre', 100)->nullable();
            $table->string('meses_descuento', 255)->nullable();
            $table->boolean('estado')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('id_descuento')->references('id_descuentos')->on('descuentos')->onDelete('restrict');
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict');
            $table->foreign('id_inscripcion')->references('cod_inscrip')->on('inscripciones')->onDelete('restrict');
            if (Schema::hasTable('cuotas')) {
                $table->foreign('id_cuota')->references('id')->on('cuotas')->onDelete('restrict');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('descuento_detalle');
    }
};
