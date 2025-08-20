<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
     public function run()
    {
        // Usuario administrador
        Usuario::create([
            'nickname' => 'admin',
            'nombre' => 'Admin',
            'ap_paterno' => 'Principal',
            'ap_materno' => 'Sistema',
            'contrasenia' => 'admin123',
            'ci' => '00000000',
            'estado' => true,
            'id_rol' => 1 // ID del rol Administrador
        ]);

        // Usuarios de prueba
        Usuario::factory()->count(15)->create();
    }
}
