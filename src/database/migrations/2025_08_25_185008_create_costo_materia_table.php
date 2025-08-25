<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('costo_materia', function (Blueprint $table) {
            $table->bigIncrements('id_costo_materia');
            $table->string('sigla_materia', 255);
            $table->string('gestion', 30);
            $table->decimal('nro_creditos', 10, 2);
            $table->string('nombre_materia', 30);
            $table->decimal('monto_materia', 10, 2)->nullable();
            $table->unsignedBigInteger('id_usuario');
            $table->timestamps();
            
            // Clave primaria compuesta
            $table->primary(['id_costo_materia', 'sigla_materia', 'gestion']);
            
            // Foreign keys (asumiendo que existen estas tablas)
            $table->foreign('sigla_materia')->references('sigla_materia')->on('materia')->onDelete('restrict');
            $table->foreign('gestion')->references('gestion')->on('gestion')->onDelete('restrict');
            $table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('costo_materia');
    }
};
