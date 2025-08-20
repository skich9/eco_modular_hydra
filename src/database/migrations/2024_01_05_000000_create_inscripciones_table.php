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
        Schema::create('inscripciones', function (Blueprint $table) {
            $table->id('cod_inscrip');
            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario');
            $table->unsignedBigInteger('cod_ceta');
            $table->string('cod_pensum', 50);
            $table->tinyInteger('nro_materia');
            $table->integer('nro_materia_aprob');
            $table->string('gestion', 20);
            $table->string('tipo_estudiante', 20)->nullable();
            $table->date('fecha_inscripcion')->nullable();
            $table->string('tipo_inscripcion', 30);
            $table->timestamps();
            $table->softDeletes();

            // Relaciones individuales
            $table->foreign('cod_ceta')
                  ->references('cod_ceta')
                  ->on('estudiantes')
                  ->onDelete('restrict');

            $table->foreign('cod_pensum')
                  ->references('cod_pensum')
                  ->on('pensums')
                  ->onDelete('restrict');

            $table->foreign('gestion')
                  ->references('gestion')
                  ->on('gestion')
                  ->onDelete('restrict');

            // Añadir índice compuesto para la relación con kardex_notas
            $table->index(['cod_inscrip', 'cod_pensum']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inscripciones');
    }
};
