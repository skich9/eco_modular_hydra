<?php

namespace Database\Seeders;
use DB;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SinSucursalPensumSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // as agregan los datos a la tabla
        DB::table('sin_sucursal_pensum')->insert([
            [
                'codigo_sucursal' => 0,
                'cod_pensum' => 'EEA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo_sucursal' => 0,
                'cod_pensum' => 'EEA-19',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo_sucursal' => 0,
                'cod_pensum' => 'EEA-26',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // carrera de mecanica
            [
                'codigo_sucursal' => 1,
                'cod_pensum' => 'MEA',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo_sucursal' => 1,
                'cod_pensum' => '04-MTZ',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo_sucursal' => 1,
                'cod_pensum' => '04-MTZ-17',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo_sucursal' => 1,
                'cod_pensum' => '04-MTZ-23',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
