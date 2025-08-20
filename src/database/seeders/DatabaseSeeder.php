<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $this->call([
            RolSeeder::class,
            FuncionSeeder::class,
            UsuarioSeeder::class,
            PensumSeeder::class,
            ParametrosEconomicosSeeder::class,
            CostoSemestralSeeder::class,
            AsignacionCostosSeeder::class,
            CarreraSeeder::class,
        ]);
    }
}