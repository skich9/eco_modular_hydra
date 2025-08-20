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
        Schema::create('items_cobro', function (Blueprint $table) {
            $table->integer('id_item')->autoIncrement();
            $table->integer('codigo_producto_impuesto')->nullable();
            $table->string('codigo_producto_interno', 15);
            $table->integer('unidad_medida');
            $table->string('nombre_servicio', 100);
            $table->decimal('nro_creditos', 10, 2);
            $table->integer('costo')->nullable();
            $table->boolean('facturado');
            $table->string('actividad_economica', 255);
            $table->string('descripcion', 50)->nullable();
            $table->string('tipo_item', 40);
            $table->boolean('estado')->nullable();
            $table->integer('id_parametro_economico');
            $table->timestamps();
            
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
        Schema::dropIfExists('items_cobro');
    }
};
