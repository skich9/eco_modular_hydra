<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tipo_cobro')) {
            Schema::create('tipo_cobro', function (Blueprint $table) {
                $table->string('cod_tipo_cobro', 50)->primary();
                $table->string('nombre_tipo_cobro', 255);
                $table->text('descripcion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamps();

                // Adding index for better performance on nombre_tipo_cobro
                $table->index('nombre_tipo_cobro');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_cobro');
    }
};
