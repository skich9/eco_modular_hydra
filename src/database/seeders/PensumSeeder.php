<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PensumSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Insertar pensums básicos para pruebas
        DB::table('pensums')->insert([
            'cod_pensum' => 'SIS-2025',
            'nombre' => 'Pensum 2025 - Ingeniería de Sistemas',
            'descripcion' => 'Plan de estudios Ingeniería de Sistemas 2025',
            'codigo_carrera' => 'ING-SIS', // coincidencia exacta con CarreraSeeder
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pensums')->insert([
            'cod_pensum' => 'ADM-2024',
            'nombre' => 'Pensum 2024 - Administración de Empresas',
            'descripcion' => 'Plan de estudios Administración de Empresas 2024',
            'codigo_carrera' => 'ADM-EMP', // coincidencia exacta
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pensums')->insert([
            'cod_pensum' => 'IND-2025',
            'nombre' => 'Pensum 2025 - Ingeniería Industrial',
            'descripcion' => 'Plan de estudios Ingeniería Industrial 2025',
            'codigo_carrera' => 'ING-IND', // coincidencia exacta
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
