<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sga_push_cobros', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cobro_uid', 50)->unique(); // anio_cobro-nro_cobro
            $table->integer('nro_cobro');
            $table->integer('anio_cobro');
            $table->unsignedBigInteger('cod_ceta');
            $table->string('cod_pensum', 50);
            $table->string('destino_conn', 20); // sga_elec | sga_mec
            $table->string('destino_tabla', 30); // pago | pago_multa | matricula
            $table->json('payload');
            $table->json('response')->nullable();
            $table->boolean('sincronizado')->default(false);
            $table->tinyInteger('intentos')->default(0);
            $table->text('ultimo_error')->nullable();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();

            $table->index(['sincronizado', 'destino_conn']);
            $table->index(['cod_ceta', 'cod_pensum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sga_push_cobros');
    }
};
