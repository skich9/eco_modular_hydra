<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PensumResolucionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pensums = [
            [
                'cod_pensum' => '04-MTZ',
                'codigo_carrera' => 'MEA',
                'nombre' => '04-MTZ',
                'descripcion' => 'R.M. 066/2012',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
            [
                'cod_pensum' => '04-MTZ-17',
                'codigo_carrera' => 'MEA',
                'nombre' => '04-MTZ-17',
                'descripcion' => 'R.M. 082/2018',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
            [
                'cod_pensum' => '04-MTZ-23',
                'codigo_carrera' => 'MEA',
                'nombre' => '04-MTZ-23',
                'descripcion' => 'R.M. 210/2023',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
            [
                'cod_pensum' => 'EEA',
                'codigo_carrera' => 'MEA',
                'nombre' => 'EEA',
                'descripcion' => 'R.M. 341/2012',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
            [
                'cod_pensum' => 'EEA-19',
                'codigo_carrera' => 'EEA',
                'nombre' => 'EEA-19',
                'descripcion' => 'R.M. 595/2019',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
            [
                'cod_pensum' => 'MEA',
                'codigo_carrera' => 'MEA',
                'nombre' => 'MEA',
                'descripcion' => 'R.A. 513/2001',
                'cantidad_semestres' => null,
                'orden' => null,
                'nivel' => null,
                'estado' => true,
                'created_at' => '2025-09-12 20:28:59',
                'updated_at' => '2025-12-18 16:02:32',
            ],
        ];

        foreach ($pensums as $pensum) {
            DB::table('pensums')->updateOrInsert(
                ['cod_pensum' => $pensum['cod_pensum']],
                $pensum
            );
        }
    }
}
