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
        Schema::create('materia', function (Blueprint $table) {
            $table->string('sigla_materia', 255);
            $table->string('cod_pensum', 50);
            $table->string('nombre_materia', 50);
            $table->string('nombre_material_oficial', 50);
            $table->boolean('estado');
            $table->integer('orden');
            $table->string('descripcion', 50)->nullable();
            $table->integer('id_parametro_economico');
            $table->decimal('nro_creditos', 10, 2);
            $table->timestamps();
            
            $table->primary(['sigla_materia', 'cod_pensum']);
            
            $table->foreign('cod_pensum')
                  ->references('cod_pensum')
                  ->on('pensums')
                  ->onDelete('restrict')
                  ->onUpdate('restrict');
                  
            $table->foreign('id_parametro_economico')
                  ->references('id_parametro_economico')
                  ->on('parametros_economicos')
                  ->onDelete('restrict')
                  ->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materia');
    }
};
