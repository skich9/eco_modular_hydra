<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cambia 'numeroordenoriginante' en qr_transacciones de DECIMAL(20,0) a VARCHAR(50).
     * Esto alinea el tipo con qr_respuestas_banco (que ya usa VARCHAR(50)) y evita
     * pérdida de datos por conversiones numéricas al guardar el valor tal como llega
     * del proveedor MC4.
     */
    public function up(): void
    {
        Schema::table('qr_transacciones', function (Blueprint $table) {
            $table->string('numeroordenoriginante', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('qr_transacciones', function (Blueprint $table) {
            $table->decimal('numeroordenoriginante', 20, 0)->nullable()->change();
        });
    }
};
