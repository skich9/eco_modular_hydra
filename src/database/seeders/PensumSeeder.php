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
            'cod_pensum' => 'EEA-1998',
            'nombre' => 'Pensum-1998 - Electricidad y Electrónica',
            'descripcion' => 'Plan de estudios Electricidad y Electrónica 1998',
            'codigo_carrera' => 'EEA', // coincidencia exacta con CarreraSeeder
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pensums')->insert([
            'cod_pensum' => 'MEA-1998',
            'nombre' => 'Pensum 1998 - Mecanica Automotriz',
            'descripcion' => 'Plan de estudios Mecanica Automotriz 1998',
            'codigo_carrera' => 'MEA', // coincidencia exacta
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pensums')->insert([
            'cod_pensum' => 'SEA-1998',
            'nombre' => 'Pensum 1998 - Secretariado Ejecutivo',
            'descripcion' => 'Plan de estudios Secretariado Ejecutivo 1998',
            'codigo_carrera' => 'SEA', // coincidencia exacta
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
