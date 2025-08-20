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
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = AsignacionCostos::class;
    
    public function definition(): array
    {
        // Obtener inscripciones existentes
        $inscripciones = DB::table('inscripciones')->select('cod_inscrip')->get();
        if ($inscripciones->isEmpty()) {
            // Si no hay inscripciones, usar valores por defecto
            $cod_inscrip = 1;
        } else {
            $cod_inscrip = $this->faker->randomElement($inscripciones)->cod_inscrip;
        }
        
        // Obtener costos semestrales existentes
        $costosSemestrales = CostoSemestral::select('id_costo_semestral', 'cod_pensum')->get();
        if ($costosSemestrales->isEmpty()) {
            // Si no hay costos semestrales, usar valores por defecto
            $id_costo_semestral = 1;
            $cod_pensum = 'ING-SIS';
        } else {
            $costoSeleccionado = $this->faker->randomElement($costosSemestrales);
            $id_costo_semestral = $costoSeleccionado->id_costo_semestral;
            $cod_pensum = $costoSeleccionado->cod_pensum;
        }
        
        return [
            'cod_pensum' => $cod_pensum,
            'cod_inscrip' => $cod_inscrip,
            'monto' => $this->faker->randomFloat(2, 100, 1000),
            'observaciones' => $this->faker->sentence(),
            'estado' => $this->faker->boolean(),
            'id_costo_semestral' => $id_costo_semestral,
        ];
    }
}
