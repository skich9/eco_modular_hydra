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
        Schema::table('items_cobro', function (Blueprint $table) {
            // Cambiar descripción a tipo TEXT como en el SQL original
            $table->text('descripcion')->nullable()->change();
            
            // Cambiar costo a decimal para mayor precisión
            $table->decimal('costo', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items_cobro', function (Blueprint $table) {
            // Restaurar a los tipos anteriores
            $table->string('descripcion', 50)->nullable()->change();
            $table->integer('costo')->nullable()->change();
        });
    }
};
