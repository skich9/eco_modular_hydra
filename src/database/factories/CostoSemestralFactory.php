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
        $gestiones = ['1/1999', '2/1999', '1/2000', '2/2000'];
        $semestres = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];

        // Obtener cod_pensum válidos desde la base de datos
        $codPensums = \DB::table('pensums')->pluck('cod_pensum')->toArray();

        return [
            'cod_pensum' => $this->faker->randomElement($codPensums), // ahora siempre válido
            'gestion' => $this->faker->randomElement($gestiones),
            'semestre' => $this->faker->randomElement($semestres),
            'monto_semestre' => $this->faker->randomFloat(2, 500, 2000),
            'id_usuario' => $this->faker->randomElement($usuarios),
        ];
    }
}
