<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefDescuentosBecaEspecialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['cod_beca' => 3, 'nombre_beca' => 'Beca DDE', 'descripcion' => 'BECA MINISTERIO DE EDUCACION', 'monto' => 100, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 4, 'nombre_beca' => 'Beca 2 carrera', 'descripcion' => 'BECA DOBLE CARRERA', 'monto' => 10, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 5, 'nombre_beca' => 'Beca 2 Hermanos', 'descripcion' => '2 ESTUDIANTES EN LA MISMA O DIF. CARRERA', 'monto' => 10, 'porcentaje' => 1, 'estado' => 0, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 6, 'nombre_beca' => 'Beca PCETA', 'descripcion' => 'Programa especial de capacitación', 'monto' => 100, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 7, 'nombre_beca' => 'Beca Academica 15%', 'descripcion' => '15% de descuento por buenas notas', 'monto' => 15, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 8, 'nombre_beca' => 'Beca Solidaria 100%', 'descripcion' => 'Beca solidaria', 'monto' => 100, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 9, 'nombre_beca' => 'BECA  CASO ESPECIAL', 'descripcion' => 'CASOS ESPECIALES', 'monto' => 100, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 10, 'nombre_beca' => 'Beca Academica 20%', 'descripcion' => '20% de descuento por buenas notas', 'monto' => 20, 'porcentaje' => 1, 'estado' => 1, 'd_i' => 0, 'beca' => 1],
            ['cod_beca' => 11, 'nombre_beca' => 'Beca Solidaria 260', 'descripcion' => 'Descuento para el hijo del profesor Victor Veizaga', 'monto' => 260, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 12, 'nombre_beca' => 'Beca Solidaria 280', 'descripcion' => 'Descuento para el sobrino de la Sra. Roxana', 'monto' => 280, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 13, 'nombre_beca' => 'Beca Solidaria 160', 'descripcion' => 'Autoriza Lic. Ramiro P.', 'monto' => 160, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 14, 'nombre_beca' => 'Nivelacion Beca', 'descripcion' => '', 'monto' => 168, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 15, 'nombre_beca' => 'Beca Solidaria 50%', 'descripcion' => 'Beca solidaria del 50% para Prof', 'monto' => 50, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 16, 'nombre_beca' => 'Beca Solidaria  300', 'descripcion' => 'Descuento para el hijo del profesor Victor Veizaga', 'monto' => 300, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 17, 'nombre_beca' => 'Beca solidaria 25%', 'descripcion' => 'Autorizado  por Lic. Ramiro Perez', 'monto' => 25, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 18, 'nombre_beca' => 'beca adicional 60Bs', 'descripcion' => 'caso expecional aprobado por lic. ramiro Pérez en fecha 03/08/2020', 'monto' => 60, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 19, 'nombre_beca' => 'Beca rendimiento academico Bs97', 'descripcion' => 'Beca redondeada de 15 por ciento para el rendimiento académico gestión 1/2022', 'monto' => 97, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 20, 'nombre_beca' => 'Beca por Familiares 10%', 'descripcion' => 'Beca para estudiantes que son familiares', 'monto' => 10, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 21, 'nombre_beca' => 'Beca solidaria 20%', 'descripcion' => 'Beca solidaria 20%', 'monto' => 20, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 22, 'nombre_beca' => 'Beca solidaria 30%', 'descripcion' => 'Beca solidaria 30%', 'monto' => 30, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 23, 'nombre_beca' => 'Beca solidaria 15%', 'descripcion' => 'BEca solidaria otorgada a solicitud del estudiante', 'monto' => 97, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 24, 'nombre_beca' => 'Beca Familiares de Trabajadores 50%', 'descripcion' => 'Beca para estudiantes que son familiares de algún trabajador aprobado por comité de evaluación', 'monto' => 50, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 25, 'nombre_beca' => 'Beca Academica 30%', 'descripcion' => 'Beca académica por alto rendimiento', 'monto' => 30, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 26, 'nombre_beca' => 'Beca por cierre turno tarde', 'descripcion' => 'BBeca que se aplica a estudiantes que están en el turno tarde y se cierra su grupo por falta de estudiantes', 'monto' => 50, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 27, 'nombre_beca' => 'Descuento nivelación 1 materia', 'descripcion' => 'Solo cursa 1 materia', 'monto' => 520, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 28, 'nombre_beca' => 'Descuento nivelación 2 materias', 'descripcion' => 'Solo cursa 2 materias', 'monto' => 390, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 29, 'nombre_beca' => 'Descuento nivelación 3 materias', 'descripcion' => 'Solo cursa 3 materias', 'monto' => 260, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 30, 'nombre_beca' => 'Descuento 30%', 'descripcion' => 'Descuento del 30%', 'monto' => 30, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 31, 'nombre_beca' => 'Descuento 30 Bs', 'descripcion' => 'Descuento especial para 2/2020', 'monto' => 30, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 32, 'nombre_beca' => 'Descuento 120 Bs', 'descripcion' => 'Descuento especial por pandemia de 120', 'monto' => 120, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 33, 'nombre_beca' => 'Descuento 180 Bs', 'descripcion' => 'Descuento especial por pandemia de 180', 'monto' => 180, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 34, 'nombre_beca' => 'Descuento 60 Bs', 'descripcion' => 'Descuento especial por pandemia de 60', 'monto' => 60, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 35, 'nombre_beca' => 'Descuento 20 Bs', 'descripcion' => 'Descuento especial de 20 Bs', 'monto' => 20, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 36, 'nombre_beca' => 'Descuento 50 Bs', 'descripcion' => 'Descuento por valor de Bs50.- aplicable a los estudiantes', 'monto' => 50, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 37, 'nombre_beca' => 'Descuento 10%', 'descripcion' => 'Descuento del 10%', 'monto' => 10, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 38, 'nombre_beca' => 'Descuento 90Bs', 'descripcion' => 'Descuento por valor de 90 Bolivianos', 'monto' => 90, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 39, 'nombre_beca' => 'Mensualidades Pagadas', 'descripcion' => 'Pago adelantado de mensualidades', 'monto' => 100, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 40, 'nombre_beca' => 'Descuento Pago Semestre completo', 'descripcion' => '', 'monto' => 20, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 41, 'nombre_beca' => 'Descuento 80 Bs', 'descripcion' => 'Descuento 80 Bs por pago del año completo', 'monto' => 80, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 42, 'nombre_beca' => 'Anticipo de mensualidades(por semestre completo Turno Tarde)', 'descripcion' => 'Estudiantes que se inscriben en el turno de la tarde y pagan todo el semestre', 'monto' => 115, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 43, 'nombre_beca' => 'Descuento por Turno Tarde', 'descripcion' => 'Mensualidad de estudiante que paga mes a mes del turno tarde', 'monto' => 50, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 44, 'nombre_beca' => 'Descuento por Adelanto de mensualidad', 'descripcion' => 'Descuento que utiliza para regularizar adelantos de mensualidad realizados por el estúdiate', 'monto' => 115, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 45, 'nombre_beca' => 'cierre turno tarde pago de semestre completo', 'descripcion' => 'descuento por cierre de turno tarde y pago de semestre completo', 'monto' => 110, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 48, 'nombre_beca' => 'Descuento deportivo', 'descripcion' => 'Descuento para estudiantes que ganan un campeonato', 'monto' => 100, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 49, 'nombre_beca' => 'Beca presentacion', 'descripcion' => 'Prueba de beca', 'monto' => 20, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 1],
            ['cod_beca' => 50, 'nombre_beca' => 'Descuento pago de semestre completo', 'descripcion' => 'Pago de semestre completo', 'monto' => 10, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 51, 'nombre_beca' => 'Descuento institucional Mañana', 'descripcion' => 'Descuento institucional que se aplica a los estudiantes bajo requisito', 'monto' => 40, 'porcentaje' => 0, 'estado' => 1, 'd_i' => 1, 'beca' => 0],
            ['cod_beca' => 52, 'nombre_beca' => 'Descuento institucional Tarde', 'descripcion' => 'Descuento institucional que se aplica bajo requerimiento', 'monto' => 35, 'porcentaje' => 0, 'estado' => 1, 'd_i' => 1, 'beca' => 0],
            ['cod_beca' => 53, 'nombre_beca' => 'Descuento institucional Noche', 'descripcion' => 'Descuento institucional que se aplica bajo requerimiento', 'monto' => 40, 'porcentaje' => 0, 'estado' => 1, 'd_i' => 1, 'beca' => 0],
            ['cod_beca' => 54, 'nombre_beca' => 'Descuento Institucional Semestre completo', 'descripcion' => 'Descuento institucional del 10%', 'monto' => 10, 'porcentaje' => 1, 'estado' => 1, 'd_i' => 1, 'beca' => 0],
            ['cod_beca' => 55, 'nombre_beca' => 'Descuento prueba de prorrateo', 'descripcion' => 'Descuento de prueba solamente', 'monto' => 5, 'porcentaje' => 1, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
            ['cod_beca' => 56, 'nombre_beca' => 'Descuento_Sincronizacion', 'descripcion' => 'Descuento que engloba a los descuentos que tiene en el sga', 'monto' => 0, 'porcentaje' => 0, 'estado' => 1, 'd_i' => NULL, 'beca' => 0],
        ];

        foreach ($data as $item) {
            DB::table('def_descuentos_beca')->updateOrInsert(
                ['cod_beca' => $item['cod_beca']],
                $item
            );
        }
    }
}
