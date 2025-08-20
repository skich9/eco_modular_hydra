<?php

namespace Database\Factories;

use App\Models\CostoSemestral;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CostoSemestral>
 */
class CostoSemestralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = CostoSemestral::class;
    
    public function definition(): array
    {
        $usuarios = Usuario::pluck('id_usuario')->toArray();
        $gestiones = ['2024-1', '2024-2', '2025-1', '2025-2'];
        $semestres = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        
        return [
            'cod_pensum' => 'ING-SIS', // Valor por defecto, puede cambiarse según necesidad
            'gestion' => $this->faker->randomElement($gestiones),
            'semestre' => $this->faker->randomElement($semestres),
            'monto_semestre' => $this->faker->randomFloat(2, 500, 2000),
            'id_usuario' => $this->faker->randomElement($usuarios),
        ];
    }
}
