<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('compromisos', function (Blueprint $table) {
            $table->bigIncrements('id_compromisos');
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('cod_ceta');
            $table->decimal('monto', 10, 2);
            $table->date('fecha_compromiso');
            $table->date('fecha_vencimiento');
            $table->string('estado', 255);
            $table->text('descripcion')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('usuario_id')->references('id_usuario')->on('usuarios')->onDelete('restrict');
            $table->foreign('cod_ceta')->references('cod_ceta')->on('estudiantes')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('compromisos');
    }
};
