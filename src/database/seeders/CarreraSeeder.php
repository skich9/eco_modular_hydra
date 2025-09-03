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
                'codigo_carrera' => 'EEA',
                'nombre' => 'Electricidad y Electrónica',
                'descripcion' => 'Carrera de Electricidad, Electrónica.',
                'prefijo_matricula' => 'EEA',
                'estado' => 1,
            ],
            [
                'codigo_carrera' => 'MEA',
                'nombre' => 'Mecanica Automotriz',
                'descripcion' => 'Carrera de Mecanica Automotriz',
                'prefijo_matricula' => 'MEA',
                'estado' => 1,
            ],
            [
                'codigo_carrera' => 'SEA',
                'nombre' => 'Secretariado Ejecutivo',
                'descripcion' => 'Carrera de Secretariado Ejecutivo',
                'prefijo_matricula' => 'SEA',
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

