<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_bancaria', function (Blueprint $table) {
            $table->integer('anio_deposito');
            $table->integer('correlativo');
            $table->string('usuario', 100);
            $table->timestamp('fecha_nota');
            $table->unsignedBigInteger('cod_ceta')->nullable();
            $table->decimal('monto', 10, 2);
            $table->text('concepto')->nullable();
            $table->text('nro_factura');
            $table->text('nro_recibo');
            $table->text('banco')->nullable();
            $table->text('fecha_deposito')->nullable();
            $table->text('nro_transaccion')->nullable();
            $table->char('prefijo_carrera', 1)->default('E');
            $table->text('concepto_est')->nullable();
            $table->text('observacion')->nullable();
            $table->boolean('anulado')->default(false);
            $table->string('tipo_nota', 10)->default('D');
            $table->string('banco_origen', 200)->nullable();
            $table->string('nro_tarjeta', 200)->nullable();
            $table->primary(['anio_deposito', 'correlativo', 'tipo_nota']);
            $table->index('cod_ceta');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_bancaria');
    }
};
