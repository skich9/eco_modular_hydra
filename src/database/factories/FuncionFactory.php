<?php

namespace Database\Factories;

use App\Models\Funcion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Funcion>
 */
class FuncionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Funcion::class;

    public function definition()
    {
        return [
            'nombre' => $this->faker->unique()->word(),
            'descripcion' => $this->faker->sentence(),
            'estado' => $this->faker->boolean(90) // 90% de probabilidad de true
        ];
    }
}
