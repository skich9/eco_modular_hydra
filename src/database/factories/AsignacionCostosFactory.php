<?php

namespace Database\Factories;

use App\Models\AsignacionCostos;
use App\Models\CostoSemestral;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AsignacionCostos>
 */
class AsignacionCostosFactory extends Factory
{
    protected $model = AsignacionCostos::class;
    
    public function definition(): array
    {
        // Obtener inscripciones existentes
        $inscripciones = DB::table('inscripciones')->pluck('cod_inscrip')->toArray();
        if (empty($inscripciones)) {
            throw new \Exception("No hay inscripciones en la base de datos. Ejecuta primero InscripcionSeeder.");
        }
        $cod_inscrip = $this->faker->randomElement($inscripciones);
        
        // Obtener costos semestrales existentes
        $costosSemestrales = CostoSemestral::all();
        if ($costosSemestrales->isEmpty()) {
            throw new \Exception("No hay costos semestrales en la base de datos. Ejecuta primero CostoSemestralSeeder.");
        }
        $costoSeleccionado = $this->faker->randomElement($costosSemestrales);
        
        return [
            'cod_pensum' => $costoSeleccionado->cod_pensum, // siempre existente
            'cod_inscrip' => $cod_inscrip,                  // siempre existente
            'monto' => $this->faker->randomFloat(2, 100, 1000),
            'observaciones' => $this->faker->sentence(),
            'estado' => $this->faker->boolean(),
            'id_costo_semestral' => $costoSeleccionado->id_costo_semestral, // siempre existente
        ];
    }
}

