<?php

namespace Database\Seeders;

use App\Models\Funcion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FuncionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Funcion::factory()->count(5)->create(); // Crea 5 funciones
    }
}
