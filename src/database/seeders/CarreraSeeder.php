<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarreraSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Definimos las carreras
        $carreras = [
            [
                'codigo_carrera' => 'ING-SIS',
                'nombre' => 'Ingeniería de Sistemas',
                'descripcion' => 'Carrera de Ingeniería de Sistemas y Computación',
                'prefijo_matricula' => 'SIS',
                'estado' => 1,
            ],
            [
                'codigo_carrera' => 'ING-IND',
                'nombre' => 'Ingeniería Industrial',
                'descripcion' => 'Carrera de Ingeniería Industrial y Procesos',
                'prefijo_matricula' => 'IND',
                'estado' => 1,
            ],
            [
                'codigo_carrera' => 'ADM-EMP',
                'nombre' => 'Administración de Empresas',
                'descripcion' => 'Carrera de Administración de Empresas',
                'prefijo_matricula' => 'ADM',
                'estado' => 1,
            ],
        ];

        // Insertamos o actualizamos cada carrera
        foreach ($carreras as $carrera) {
            DB::table('carrera')->updateOrInsert(
                ['codigo_carrera' => $carrera['codigo_carrera']],
                $carrera + [
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }
}

