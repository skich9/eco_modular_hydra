<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kardex_notas', function (Blueprint $table) {
            $table->unsignedBigInteger('cod_ceta');
            $table->string('cod_pensum', 50);
            $table->unsignedBigInteger('cod_inscrip');
            $table->string('tipo_incripcion', 50);
            $table->integer('cod_kardex');
            $table->string('sigla_materia', 30);
            $table->string('observacion', 50);
            $table->unsignedBigInteger('id_usuario');
            $table->timestamps();

            // Clave primaria compuesta
            $table->primary([
                'cod_ceta', 
                'cod_pensum', 
                'cod_inscrip', 
                'tipo_incripcion', 
                'cod_kardex'
            ]);

            // Relación con estudiantes
            $table->foreign('cod_ceta')
                  ->references('cod_ceta')
                  ->on('estudiantes')
                  ->onDelete('restrict');

            // Relación compuesta con inscripciones
            $table->foreign(['cod_inscrip', 'cod_pensum'])
                  ->references(['cod_inscrip', 'cod_pensum'])
                  ->on('inscripciones')
                  ->onDelete('restrict');

            // Relación compuesta con materia
            $table->foreign(['cod_pensum', 'sigla_materia'])
                  ->references(['cod_pensum', 'sigla_materia'])
                  ->on('materia')
                  ->onDelete('restrict');

            // Relación con usuarios
            $table->foreign('id_usuario')
                  ->references('id_usuario')
                  ->on('usuarios')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kardex_notas');
    }
};
