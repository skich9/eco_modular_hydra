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
        Schema::create('parametros_economicos', function (Blueprint $table) {
            $table->integer('id_parametro_economico')->autoIncrement();
            $table->string('nombre', 20);
            $table->decimal('valor', 10, 2);
            $table->boolean('estado');
            $table->string('descripcion', 50);
            $table->timestamps();
            
            $table->primary(['id_parametro_economico', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametros_economicos');
    }
};
