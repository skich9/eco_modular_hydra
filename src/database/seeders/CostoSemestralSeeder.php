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
        // Ingeniería de Sistemas
        for ($semestre = 1; $semestre <= 10; $semestre++) {
            CostoSemestral::create([
                'cod_pensum' => 'EEA-1998', // corregido
                'gestion' => '1/1999',
                'semestre' => (string)$semestre,
                'monto_semestre' => 1000 + ($semestre * 50),
                'costo_fijo' => 0,
                'valor_credito' => 0,
                'id_usuario' => 1,
            ]);
        }

        // Administración de Empresas
        for ($semestre = 1; $semestre <= 8; $semestre++) {
            CostoSemestral::create([
                'cod_pensum' => 'MEA-1998', // corregido
                'gestion' => '1/1999',
                'semestre' => (string)$semestre,
                'monto_semestre' => 900 + ($semestre * 40),
                'costo_fijo' => 0,
                'valor_credito' => 0,
                'id_usuario' => 1,
            ]);
        }
        // Crear algunos costos semestrales aleatorios adicionales
        CostoSemestral::factory()->count(5)->create();
    }
}
