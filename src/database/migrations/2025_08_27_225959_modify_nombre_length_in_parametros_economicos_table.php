<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('parametros_economicos', function (Blueprint $table) {
            $table->string('nombre', 100)->change(); // Aumentar a 100 caracteres
        });
    }

    public function down()
    {
        Schema::table('parametros_economicos', function (Blueprint $table) {
            $table->string('nombre', 20)->change(); // Volver a 20 caracteres
        });
    }
};
