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
            ParametrosEconomicosSeeder::class,
            CarreraSeeder::class,
            PensumSeeder::class,
            GestionSeeder::class,
            CostoSemestralSeeder::class,
            AsignacionCostosSeeder::class,
        ]);
    }
}