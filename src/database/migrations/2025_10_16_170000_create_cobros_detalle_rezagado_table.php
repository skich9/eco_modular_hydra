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
        Schema::create('rezagados', function (Blueprint $table) {
            $table->unsignedBigInteger('cod_inscrip');
            $table->integer('num_rezagado');
            $table->integer('num_pago_rezagado');
            $table->integer('num_factura')->nullable();
            $table->integer('num_recibo')->nullable();
            $table->timestamp('fecha_pago');
            $table->decimal('monto', 10, 2);
            $table->boolean('pago_completo');
            $table->string('observaciones', 150)->nullable();
            $table->unsignedBigInteger('usuario');
            $table->string('materia', 255)->nullable();
            $table->char('parcial', 1)->nullable();
            $table->timestamps();

            $table->primary(['cod_inscrip','num_rezagado','num_pago_rezagado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rezagados');
    }
};
