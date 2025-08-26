<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('prorrogas', function (Blueprint $table) {
            $table->bigIncrements('id_prorroga');
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('cod_ceta');
            $table->unsignedBigInteger('cuota_id')->nullable();
            $table->date('fecha_solicitud');
            $table->date('fecha_prorroga');
            $table->string('estado', 255);
            $table->text('motivo')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('usuario_id')->references('id_usuario')->on('usuarios')->onDelete('restrict');
            $table->foreign('cod_ceta')->references('cod_ceta')->on('estudiantes')->onDelete('restrict');
            if (Schema::hasTable('cuotas')) {
                $table->foreign('cuota_id')->references('id')->on('cuotas')->onDelete('restrict');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('prorrogas');
    }
};