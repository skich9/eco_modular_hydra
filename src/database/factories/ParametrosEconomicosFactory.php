<?php

namespace Database\Factories;

use App\Models\ParametrosEconomicos;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ParametrosEconomicos>
 */
class ParametrosEconomicosFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = ParametrosEconomicos::class;
    
    public function definition(): array
    {
        return [
            'nombre' => substr($this->faker->unique()->word(), 0, 20),
            'descripcion' => substr($this->faker->sentence(), 0, 50),
            'valor' => $this->faker->randomFloat(2, 10, 5000),
            'estado' => $this->faker->boolean(),
        ];
    }
}
