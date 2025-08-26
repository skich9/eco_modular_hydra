<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('asignacion_costos')) {
            Schema::create('asignacion_costos', function (Blueprint $table) {
                $table->string('cod_pensum', 50);
                $table->unsignedBigInteger('cod_inscrip');
                $table->bigIncrements('id_asignacion_costo');
                $table->decimal('monto', 10, 2);
                $table->text('observaciones')->nullable();
                $table->boolean('estado')->nullable();
                $table->unsignedBigInteger('id_costo_semestral');
                $table->unsignedBigInteger('id_descuentoDetalle')->nullable();
                $table->unsignedBigInteger('id_prorroga')->nullable();
                $table->unsignedBigInteger('id_compromisos')->nullable();
                $table->timestamps();

                // Clave primaria compuesta
                $table->primary(['cod_pensum', 'cod_inscrip', 'id_asignacion_costo']);

                // Foreign keys
                $table->foreign('cod_pensum')->references('cod_pensum')->on('pensums')->onDelete('restrict');
                $table->foreign('cod_inscrip')->references('cod_inscrip')->on('inscripciones')->onDelete('restrict');
                $table->foreign('id_costo_semestral')->references('id_costo_semestral')->on('costo_semestral')->onDelete('restrict');
                $table->foreign('id_descuentoDetalle')->references('id_descuento_detalle')->on('descuento_detalle')->onDelete('restrict');
                $table->foreign('id_prorroga')->references('id_prorroga')->on('prorrogas')->onDelete('restrict');
                $table->foreign('id_compromisos')->references('id_compromisos')->on('compromisos')->onDelete('restrict');
            });
        }
        // Si la tabla ya existe, no hacemos nada aquí para evitar errores de duplicado.
        // Cualquier ajuste de columnas/llaves debe ir en una migración de alteración separada.
    }

    public function down()
    {
        Schema::dropIfExists('asignacion_costos');
    }
};
