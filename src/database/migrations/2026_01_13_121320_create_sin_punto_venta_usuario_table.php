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
        Schema::create('sin_punto_venta_usuario', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();
            $table->string('codigo_punto_venta');
            $table->integer('codigo_sucursal');
            $table->integer('codigo_ambiente');
            // $table->foreignId('codigo_punto_venta')->constrained('sin_punto_venta','codigo_punto_venta')->cascadeOnDelete();
            $table->timestamp('vencimiento_asig')->nullable();


            $table->boolean('activo')->default(true);


            $table->foreignId('usuario_crea')->constrained('usuarios', 'id_usuario')->cascadeOnDelete();


            $table->foreign('codigo_punto_venta')
                ->references('codigo_punto_venta')
                ->on('sin_punto_venta')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sin_punto_venta_usuario');
    }
};
