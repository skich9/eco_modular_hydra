<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Usuario;
use App\Models\Rol;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear rol admin si no existe
        $adminRole = Rol::firstOrCreate([
            'nombre' => 'admin'
        ], [
            'descripcion' => 'Administrador del sistema',
            'estado' => true
        ]);

        // Crear usuario admin de prueba
        $admin = Usuario::where('nickname', 'admin')->first();
        if (!$admin) {
            $admin = new Usuario();
            $admin->nickname = 'admin';
            $admin->nombre = 'Administrador';
            $admin->ap_paterno = 'Sistema';
            $admin->ap_materno = '';
            $admin->contrasenia = 'password'; // Se hashea automáticamente por el mutator
            $admin->ci = '12345678';
            $admin->estado = true;
            $admin->id_rol = $adminRole->id_rol;
            $admin->save();
        }

        // Crear usuario test
        Usuario::firstOrCreate([
            'nickname' => 'test'
        ], [
            'nombre' => 'Usuario',
            'ap_paterno' => 'Prueba',
            'ap_materno' => '',
            'contrasenia' => '123456',
            'ci' => '87654321',
            'estado' => true,
            'id_rol' => $adminRole->id_rol
        ]);
    }
}
