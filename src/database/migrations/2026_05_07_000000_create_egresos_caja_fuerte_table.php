<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egresos_caja_fuerte', function (Blueprint $table) {
            $table->bigIncrements('codigo_egreso');
            $table->string('correlativo', 50)->unique();
            $table->date('fecha_egreso');
            $table->decimal('monto', 12, 2);
            $table->string('descripcion', 255);
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('usuario');
            $table->unsignedBigInteger('usuario_modifica')->nullable();
            $table->boolean('anular')->default(false);
            $table->unsignedBigInteger('id_caja_actividad');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('egresos_caja_fuerte');
    }
};
