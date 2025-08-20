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
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id('id_usuario');
            $table->string('nickname', 40);
            $table->string('nombre', 30);
            $table->string('ap_paterno', 40);
            $table->string('ap_materno', 40);
            $table->string('contrasenia', 255); // Considera usar password hashing
            $table->string('ci', 25);
            $table->boolean('estado')->nullable()->default(true);
            $table->foreignId('id_rol')->constrained('rol', 'id_rol');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
