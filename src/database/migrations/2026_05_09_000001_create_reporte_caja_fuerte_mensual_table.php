<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporte_caja_fuerte_mensual', function (Blueprint $table) {
            $table->bigIncrements('codigo_reporte');
            $table->string('cod_documento', 30);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->timestamp('fecha_impresion')->useCurrent();
            $table->decimal('monto', 12, 2)->default(0);
            $table->unsignedBigInteger('usuario');
            $table->boolean('anulado')->default(false);
            $table->string('motivo_anulacion', 255)->nullable();
            $table->unsignedBigInteger('id_caja_actividad');
            $table->foreign('id_caja_actividad')->references('id_caja_actividad')->on('cajas_actividad');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporte_caja_fuerte_mensual');
    }
};
