<?php

namespace Database\Seeders;

use App\Models\CostoSemestral;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CostoSemestralSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear costos semestrales para Ingeniería de Sistemas
        for ($semestre = 1; $semestre <= 10; $semestre++) {
            CostoSemestral::create([
                'cod_pensum' => 'ING-SIS',
                'gestion' => '2025-1',
                'semestre' => (string)$semestre,
                'monto_semestre' => 1000 + ($semestre * 50), // Incremento por semestre
                'id_usuario' => 1,
            ]);
        }
        
        // Crear costos semestrales para otra carrera (ejemplo)
        for ($semestre = 1; $semestre <= 8; $semestre++) {
            CostoSemestral::create([
                'cod_pensum' => 'ADM-EMP',
                'gestion' => '2025-1',
                'semestre' => (string)$semestre,
                'monto_semestre' => 900 + ($semestre * 40), // Incremento por semestre
                'id_usuario' => 1,
            ]);
        }
        
        // Crear algunos costos semestrales aleatorios adicionales
        CostoSemestral::factory()->count(5)->create();
    }
}
