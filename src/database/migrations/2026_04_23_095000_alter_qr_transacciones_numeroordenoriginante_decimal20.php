<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El proveedor MC4 envía 'numeroOrdenOriginante' con valores de hasta 12 dígitos
     * (ej. 999966389765). La columna estaba definida como INT (máx ~2147483647, 10 dígitos)
     * lo que causaba SQLSTATE[22003] en el callback y bloqueaba el registro de cobro.
     *
     * Se cambia a DECIMAL(20, 0) para soportar hasta 20 dígitos enteros sin decimales.
     */
    public function up(): void
    {
        Schema::table('qr_transacciones', function (Blueprint $table) {
            $table->decimal('numeroordenoriginante', 20, 0)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('qr_transacciones', function (Blueprint $table) {
            $table->unsignedBigInteger('numeroordenoriginante')->nullable()->change();
        });
    }
};
