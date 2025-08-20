<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Usuario>
 */
class UsuarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
   protected $model = Usuario::class;

    public function definition()
    {
       return [
            'nickname' => $this->faker->unique()->userName,
            'nombre' => $this->faker->firstName,
            'ap_paterno' => $this->faker->lastName,
            'ap_materno' => $this->faker->lastName,
            'contrasenia' => Hash::make('password123'), // Asegúrate de usar un hash seguro
            'ci' => $this->faker->unique()->numerify('########'),
            'estado' => $this->faker->boolean(90), // 90% de probabilidad de estar activo
            'id_rol' => \App\Models\Rol::inRandomOrder()->first()->id_rol,
        ];
    }
}
