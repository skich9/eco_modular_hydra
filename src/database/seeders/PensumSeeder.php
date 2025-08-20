<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            'cod_pensum' => 'ING-SIS',
            'nombre' => 'Ingeniería de Sistemas',
            'descripcion' => 'Carrera de Ingeniería de Sistemas',
            'codigo_carrera' => 'SIS',
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        DB::table('pensums')->insert([
            'cod_pensum' => 'ADM-EMP',
            'nombre' => 'Administración de Empresas',
            'descripcion' => 'Carrera de Administración de Empresas',
            'codigo_carrera' => 'ADM',
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        DB::table('pensums')->insert([
            'cod_pensum' => 'CONT-PUB',
            'nombre' => 'Contaduría Pública',
            'descripcion' => 'Carrera de Contaduría Pública',
            'codigo_carrera' => 'CONT',
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
