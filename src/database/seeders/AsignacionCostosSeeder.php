<?php

namespace Database\Seeders;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AsignacionCostosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar si existen inscripciones
        $inscripciones = DB::table('inscripciones')->select('cod_inscrip', 'cod_pensum')->get();
        
        if ($inscripciones->isEmpty()) {
            // Si no hay inscripciones, no podemos crear asignaciones
            $this->command->info('No hay inscripciones disponibles para crear asignaciones de costos.');
            return;
        }
        
        // Verificar si existen costos semestrales
        $costosSemestrales = CostoSemestral::all();
        
        if ($costosSemestrales->isEmpty()) {
            $this->command->info('No hay costos semestrales disponibles para crear asignaciones.');
            return;
        }
        
        // Crear asignaciones basadas en inscripciones y costos existentes
        foreach ($inscripciones as $inscripcion) {
            // Buscar costos semestrales que coincidan con el pensum de la inscripción
            $costos = $costosSemestrales->where('cod_pensum', $inscripcion->cod_pensum);
            
            if ($costos->isNotEmpty()) {
                // Seleccionar un costo semestral aleatorio para esta inscripción
                $costo = $costos->random();
                
                // Crear la asignación
                AsignacionCostos::create([
                    'cod_pensum' => $inscripcion->cod_pensum,
                    'cod_inscrip' => $inscripcion->cod_inscrip,
                    'monto' => $costo->monto * (rand(80, 100) / 100), // Variación aleatoria del monto
                    'observaciones' => 'Asignación automática por seeder',
                    'estado' => true,
                    'id_costo_semestral' => $costo->id_costo_semestral,
                ]);
            }
        }
        
        // Crear algunas asignaciones aleatorias adicionales si hay suficientes datos
        if ($inscripciones->count() >= 5 && $costosSemestrales->count() >= 5) {
            AsignacionCostos::factory()->count(10)->create();
        }
    }
}
