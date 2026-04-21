<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Usuario;

class DummyLibrosDiariosSeeder extends Seeder
{
    public function run()
    {
        // 1. Llenar catálogo de actividades
        $actividades = [
            ['id_actividad_economica' => 1, 'nombre' => 'SERVICIO EDUCATIVO EEA', 'prefijo' => 'EEA'],
            ['id_actividad_economica' => 2, 'nombre' => 'SERVICIO EDUCATIVO MEA', 'prefijo' => 'MEA'],
            ['id_actividad_economica' => 3, 'nombre' => 'SERVICIO DE TALLER EEA', 'prefijo' => 'EEA'],
            ['id_actividad_economica' => 4, 'nombre' => 'SERVICIO DE TALLER MEA', 'prefijo' => 'MEA'],
            ['id_actividad_economica' => 5, 'nombre' => 'TIENDA', 'prefijo' => 'TIE'],
        ];

        foreach ($actividades as $act) {
            DB::table('actividades_economicas')->updateOrInsert(
                ['id_actividad_economica' => $act['id_actividad_economica']],
                $act
            );
        }

        // 2. Asignar actividades a los usuarios especificados
        $nicks = [
            'AlejandraR' => 1, // SERVICIO EDUCATIVO EEA
            'isabel' => 5,     // TIENDA
            'pamela' => 2,     // SERVICIO EDUCATIVO MEA
            'carlosM' => 3     // SERVICIO DE TALLER EEA
        ];

        $userIds = [];
        foreach ($nicks as $nick => $idActividad) {
            $user = Usuario::where('nickname', $nick)->first();
            if ($user) {
                $user->id_actividad_economica = $idActividad;
                // Si el usuario no es activo, activarlo para que el modulo lo lea
                if (isset($user->estado)) {
                    $user->estado = 1; 
                }
                $user->save();
                $userIds[$nick] = $user->id_usuario;
            }
        }

        // 3. Crear registros dummy en libro_diario_cierre para los ultimos 4 dias habiles
        // Fechas: 21 (hoy), 20 (lunes), 17 (viernes), 16 (jueves)
        $dias = [
            Carbon::create(2026, 4, 21)->format('Y-m-d'),
            Carbon::create(2026, 4, 20)->format('Y-m-d'),
            Carbon::create(2026, 4, 17)->format('Y-m-d'),
            Carbon::create(2026, 4, 16)->format('Y-m-d'),
        ];

        $carreras = ['EEA', 'MEA'];

        foreach ($dias as $fecha) {
            foreach ($userIds as $nick => $idUsuario) {
                // 3 registros por día por usuario
                for ($i = 1; $i <= 3; $i++) {
                    $carreraAsignada = ($nick === 'pamela') ? 'MEA' : 'EEA';
                    $codigo_rd = "RD-{$carreraAsignada}-" . rand(1000, 9999);
                    
                    DB::table('libro_diario_cierre')->insert([
                        'id_usuario' => $idUsuario,
                        'fecha' => $fecha,
                        'orden_cierre' => $i,
                        'codigo_carrera' => $carreraAsignada,
                        'hora_cierre' => Carbon::createFromTime(10 + $i, rand(0, 59))->format('H:i:s'),
                        'correlativo' => $i,
                        'codigo_rd' => $codigo_rd,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        }
    }
}
