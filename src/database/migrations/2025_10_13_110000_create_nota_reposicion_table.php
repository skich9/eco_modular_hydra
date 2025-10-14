<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_reposicion', function (Blueprint $table) {
            $table->integer('correlativo');
            $table->string('usuario', 100);
            $table->unsignedBigInteger('cod_ceta')->nullable();
            $table->decimal('monto', 10, 2);
            $table->text('concepto_adm');
            $table->timestamp('fecha_nota');
            $table->text('concepto_est')->nullable();
            $table->text('observaciones')->nullable();
            $table->char('prefijo_carrera', 1);
            $table->boolean('anulado')->default(false);
            $table->integer('anio_reposicion');
            $table->text('nro_recibo')->nullable();
            $table->string('tipo_ingreso', 200)->nullable();
            $table->integer('cont');
            $table->primary(['cont', 'correlativo', 'anio_reposicion']);
            $table->index('cod_ceta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_reposicion');
    }
};
