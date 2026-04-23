<?php

namespace Database\Seeders;

use App\Services\PermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seeder que añade:
 *  - Rol "Tesorería" (id_rol = 9)
 *  - Función/permiso "Recepción de Ingresos" (codigo = economico_recepcion_ingresos)
 *  - Asignación a roles que ya gestionan cobros u otros ingresos (misma lógica operativa) y a Tesorería
 *
 * Usa updateOrInsert para que sea idempotente (se puede ejecutar múltiples veces sin duplicar).
 */
class RecepcionIngresosSeeder extends Seeder
{
    private const CODIGO_RECEPCION = 'economico_recepcion_ingresos';

    /** Códigos de permisos: si un rol tiene alguno, recibe Recepción de ingresos (menú alineado a cobros). */
    private const CODIGOS_ROL_COBROS_RELACIONADOS = [
        'cobros_gestionar',
        'economico_otros_ingresos',
        'economico_mod_otros_ingresos',
    ];

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

            // ── 2. Crear la función (permiso) del módulo (id 79 fijo = snapshot producción) ──
            DB::table('funciones')->updateOrInsert(
                ['id_funcion' => 79],
                [
                    'codigo'      => self::CODIGO_RECEPCION,
                    'nombre'      => 'Recepción de Ingresos',
                    'descripcion' => 'Acceso al módulo de recepción de ingresos (registro y reporte diario de cobros al tesorero)',
                    'modulo'      => 'Económico',
                    'activo'      => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );

            $idFuncionRecepcion = (int) DB::table('funciones')
                ->where('codigo', self::CODIGO_RECEPCION)
                ->value('id_funcion');

            if ($idFuncionRecepcion < 1) {
                throw new \RuntimeException('No se pudo resolver id_funcion para ' . self::CODIGO_RECEPCION);
            }

            $idsFuncionesRelacion = DB::table('funciones')
                ->whereIn('codigo', self::CODIGOS_ROL_COBROS_RELACIONADOS)
                ->pluck('id_funcion');

            $idRolesCobro = $idsFuncionesRelacion->isNotEmpty()
                ? DB::table('rol_funcion')
                    ->whereIn('id_funcion', $idsFuncionesRelacion)
                    ->distinct()
                    ->pluck('id_rol')
                : collect();

            // Admin (1) y Tesorería (9) siempre; además quien tenga permisos de cobro/otros ingresos
            $idRolesFinales = $idRolesCobro
                ->merge([1, 9])
                ->unique()
                ->values();

            foreach ($idRolesFinales as $idRol) {
                DB::table('rol_funcion')->updateOrInsert(
                    ['id_rol' => (int) $idRol, 'id_funcion' => $idFuncionRecepcion],
                    [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });

        $idF = (int) DB::table('funciones')->where('codigo', self::CODIGO_RECEPCION)->value('id_funcion');
        $nRol = $idF > 0 ? (int) DB::table('rol_funcion')->where('id_funcion', $idF)->count() : 0;

        // El login API arma `funciones` desde asignacion_funcion; sin esto, solo verían el menú quien tenga bypass (admin).
        $nAsig = 0;
        if ($idF > 0) {
            $permissionService = app(PermissionService::class);
            $rolIds = DB::table('rol_funcion')->where('id_funcion', $idF)->pluck('id_rol');
            foreach ($rolIds as $idRol) {
                $uids = DB::table('usuarios')
                    ->where('id_rol', (int) $idRol)
                    ->where('estado', 1)
                    ->pluck('id_usuario');
                foreach ($uids as $uid) {
                    $permissionService->assignFunction(
                        (int) $uid,
                        $idF,
                        null,
                        null,
                        'Sincronizado por RecepcionIngresosSeeder (rol con permiso de recepción)',
                        null
                    );
                    $nAsig++;
                }
            }
        }

        $this->command->info("✅ RecepcionIngresosSeeder: " . self::CODIGO_RECEPCION . " — rol_funcion: {$nRol}, asignación a usuarios: {$nAsig}.");
    }
}
