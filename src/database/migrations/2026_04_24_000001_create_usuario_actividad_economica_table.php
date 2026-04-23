<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M:N Usuario ⟷ Actividad económica (reemplaza el criterio único id_actividad en usuarios
     * para filtrar cajeros en recepción de ingresos).
     */
    public function up(): void
    {
        if (Schema::hasTable('usuario_actividad_economica')) {
            return;
        }

        Schema::create('usuario_actividad_economica', function (Blueprint $table) {
            $table->id();
            // Mismo tipo que `usuarios.id_usuario` ($table->id('id_usuario') → unsignedBigInteger)
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_actividad_economica');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['id_usuario', 'id_actividad_economica'], 'uae_usuario_actividad_unique');
            $table->foreign('id_usuario')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();
            $table->foreign('id_actividad_economica', 'fk_uae_actividad')
                ->references('id_actividad_economica')
                ->on('actividades_economicas')
                ->cascadeOnDelete();
        });

        if (Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'id_actividad_economica')) {
            $rows = DB::table('usuarios')
                ->whereNotNull('id_actividad_economica')
                ->get(['id_usuario', 'id_actividad_economica']);
            foreach ($rows as $r) {
                $ae = (int) $r->id_actividad_economica;
                if ($ae <= 0) {
                    continue;
                }
                if (!DB::table('actividades_economicas')->where('id_actividad_economica', $ae)->exists()) {
                    continue;
                }
                DB::table('usuario_actividad_economica')->updateOrInsert(
                    [
                        'id_usuario' => $r->id_usuario,
                        'id_actividad_economica' => $ae,
                    ],
                    [
                        'activo' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_actividad_economica');
    }
};
