<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

class RolFactory extends Factory
{
    protected $model = Rol::class;

    public function definition()
    {
        return [
        'nombre' => $this->faker->unique()->randomElement(['Administrador', 'Docente', 'Estudiante']),
        'descripcion' => $this->faker->text(200), // Limita a 200 caracteres
        'estado' => true,
    ];
    }
}