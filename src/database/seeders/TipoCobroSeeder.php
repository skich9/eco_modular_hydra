<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoCobroSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $tiposCobro = [
            [
                'cod_tipo_cobro' => 'MENSUALIDAD',
                'nombre_tipo_cobro' => 'Mensualidad',
                'descripcion' => 'Pago mensual regular de la matrícula',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'MORA',
                'nombre_tipo_cobro' => 'Mora',
                'descripcion' => 'Recargo por mora asociado a una cuota',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'NIVELACION',
                'nombre_tipo_cobro' => 'Nivelación',
                'descripcion' => 'Pago de nivelación por mora de mensualidad',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'ARRASTRE',
                'nombre_tipo_cobro' => 'Arrastre',
                'descripcion' => 'Pago de materias de arrastre',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'REINCORPORACION',
                'nombre_tipo_cobro' => 'Reincorporación',
                'descripcion' => 'Pago por reincorporación a la carrera',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'REZAGADOS',
                'nombre_tipo_cobro' => 'Rezagados',
                'descripcion' => 'Pago que realiza el estudiante cuando no realiza su examen en fecha programada',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'PRUEBA_RECUPERACION',
                'nombre_tipo_cobro' => 'Prueba de Recuperación',
                'descripcion' => 'Pago por pruebas de recuperación académica',
                'activo' => true,
            ],
            [
                'cod_tipo_cobro' => 'MATERIAL_EXTRA',
                'nombre_tipo_cobro' => 'Material Extra',
                'descripcion' => 'Pago por materiales educativos adicionales',
                'activo' => true,
            ],
        ];

        foreach ($tiposCobro as $tipoCobro) {
            DB::table('tipo_cobro')->updateOrInsert(
                ['cod_tipo_cobro' => $tipoCobro['cod_tipo_cobro']],
                array_merge($tipoCobro, [
                    'updated_at' => now(),
                    'created_at' => now(),
                ])
            );
        }
    }
}
