<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seeder que añade:
 *  - Rol "Tesorería" (id_rol = 9)
 *  - Función/permiso "Recepción de Ingresos" (id_funcion = 79, codigo = economico_recepcion_ingresos)
 *  - Asignación de esa función al rol Administrador (id_rol = 1) y al rol Tesorería (id_rol = 9)
 *
 * Usa updateOrInsert para que sea idempotente (se puede ejecutar múltiples veces sin duplicar).
 */
class RecepcionIngresosSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($now) {

            // ── 1. Crear el rol Tesorería si no existe ────────────────────────
            DB::table('rol')->updateOrInsert(
                ['id_rol' => 9],
                [
                    'nombre'      => 'Tesorería',
                    'descripcion' => 'Tesoreros encargados de recibir y custodiar los fondos cobrados por secretaría',
                    'estado'      => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );

            // ── 2. Crear la función (permiso) del módulo ──────────────────────
            DB::table('funciones')->updateOrInsert(
                ['id_funcion' => 79],
                [
                    'codigo'      => 'economico_recepcion_ingresos',
                    'nombre'      => 'Recepción de Ingresos',
                    'descripcion' => 'Acceso al módulo de recepción de ingresos (registro y reporte diario de cobros al tesorero)',
                    'modulo'      => 'Económico',
                    'activo'      => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );

            // ── 3. Asignar la función al Administrador (id_rol = 1) ───────────
            DB::table('rol_funcion')->updateOrInsert(
                ['id_rol' => 1, 'id_funcion' => 79],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // ── 4. Asignar la función al rol Tesorería (id_rol = 9) ───────────
            // (Los tesoreros también pueden consultar, aunque no registrar)
            DB::table('rol_funcion')->updateOrInsert(
                ['id_rol' => 9, 'id_funcion' => 79],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        });

        $this->command->info('✅ RecepcionIngresosSeeder: Rol Tesorería (id=9) y función economico_recepcion_ingresos (id=79) creados/verificados.');
    }
}
