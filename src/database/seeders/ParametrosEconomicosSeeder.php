<?php

namespace Database\Seeders;

use App\Models\ParametrosEconomicos;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ParametrosEconomicosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear algunos parámetros económicos predefinidos
        ParametrosEconomicos::create([
            'nombre' => 'Tasa Interes',
            'descripcion' => 'Tasa de interés anual para pagos a plazos',
            'valor' => 5.5,
            'estado' => true,
        ]);
        
        ParametrosEconomicos::create([
            'nombre' => 'Desc Pronto Pago',
            'descripcion' => 'Porcentaje de descuento por pago anticipado',
            'valor' => 10.0,
            'estado' => true,
        ]);
        
        ParametrosEconomicos::create([
            'nombre' => 'Recargo Mora',
            'descripcion' => 'Porcentaje de recargo por pagos atrasados',
            'valor' => 2.0,
            'estado' => true,
        ]);
        
        // Crear 5 parámetros económicos aleatorios adicionales
        ParametrosEconomicos::factory()->count(5)->create();
    }
}
