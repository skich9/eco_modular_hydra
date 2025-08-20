<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CarreraSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		// Asegurarnos de que las carreras existan o crearlas si no existen
		$carreras = [
			[
				'codigo_carrera' => 'ING-SIS',
				'nombre' => 'Ingeniería de Sistemas',
				'descripcion' => 'Carrera de Ingeniería de Sistemas y Computación',
				'prefijo_matricula' => 'SIS',
				'estado' => 1,
			],
			[
				'codigo_carrera' => 'ING-IND',
				'nombre' => 'Ingeniería Industrial',
				'descripcion' => 'Carrera de Ingeniería Industrial y Procesos',
				'prefijo_matricula' => 'IND',
				'estado' => 1,
			],
			[
				'codigo_carrera' => 'ADM-EMP',
				'nombre' => 'Administración de Empresas',
				'descripcion' => 'Carrera de Administración de Empresas',
				'prefijo_matricula' => 'ADM',
				'estado' => 1,
			],
		];
		
		// Insertamos o actualizamos cada carrera
		foreach ($carreras as $carrera) {
			DB::table('carrera')->updateOrInsert(
				['codigo_carrera' => $carrera['codigo_carrera']],
				$carrera + [
					'callback' => null,
					'created_at' => Carbon::now(),
					'updated_at' => Carbon::now(),
				]
			);
		}
		
		// Datos de prueba para pensums
		$pensums = [
			[
				'cod_pensum' => 'SIS-2025',
				'codigo_carrera' => 'ING-SIS',
				'nombre' => 'Pensum 2025 - Sistemas',
				'descripcion' => 'Plan de estudios Sistemas 2025',
				'cantidad_semestres' => 10,
				'orden' => 1,
				'nivel' => 'Pregrado',
				'estado' => 1,
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'cod_pensum' => 'SIS-2022',
				'codigo_carrera' => 'ING-SIS',
				'nombre' => 'Pensum 2022 - Sistemas',
				'descripcion' => 'Plan de estudios Sistemas 2022',
				'cantidad_semestres' => 10,
				'orden' => 2,
				'nivel' => 'Pregrado',
				'estado' => 1,
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'cod_pensum' => 'IND-2025',
				'codigo_carrera' => 'ING-IND',
				'nombre' => 'Pensum 2025 - Industrial',
				'descripcion' => 'Plan de estudios Industrial 2025',
				'cantidad_semestres' => 10,
				'orden' => 1,
				'nivel' => 'Pregrado',
				'estado' => 1,
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'cod_pensum' => 'ADM-2024',
				'codigo_carrera' => 'ADM-EMP',
				'nombre' => 'Pensum 2024 - Administración',
				'descripcion' => 'Plan de estudios Administración 2024',
				'cantidad_semestres' => 8,
				'orden' => 1,
				'nivel' => 'Pregrado',
				'estado' => 1,
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
		];
		
		// Insertar pensums
		DB::table('pensums')->insert($pensums);
		
		// Buscar un parámetro económico para relacionar con materias
		$parametroEconomico = DB::table('parametros_economicos')->first();
		$idParametroEconomico = $parametroEconomico ? $parametroEconomico->id_parametro_economico : 1;

		// Datos de prueba para materias con id_parametro_economico como entero
		$materias = [
			[
				'sigla_materia' => 'SIS-100',
				'cod_pensum' => 'SIS-2025',
				'nombre_materia' => 'Introducción a la Programación',
				'nombre_material_oficial' => 'Introducción a la Programación',
				'estado' => 1,
				'orden' => 1,
				'descripcion' => 'Fundamentos de programación',
				'id_parametro_economico' => $idParametroEconomico,
				'nro_creditos' => '4.00',
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'sigla_materia' => 'SIS-200',
				'cod_pensum' => 'SIS-2025',
				'nombre_materia' => 'Algoritmos y Estructuras de Datos',
				'nombre_material_oficial' => 'Algoritmos y Estructuras',
				'estado' => 1,
				'orden' => 2,
				'descripcion' => 'Algoritmos avanzados',
				'id_parametro_economico' => $idParametroEconomico,
				'nro_creditos' => '5.00',
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'sigla_materia' => 'SIS-300',
				'cod_pensum' => 'SIS-2025',
				'nombre_materia' => 'Bases de Datos',
				'nombre_material_oficial' => 'Bases de Datos Relacionales',
				'estado' => 1,
				'orden' => 3,
				'descripcion' => 'Diseño y administración de BD',
				'id_parametro_economico' => $idParametroEconomico,
				'nro_creditos' => '5.00',
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'sigla_materia' => 'IND-100',
				'cod_pensum' => 'IND-2025',
				'nombre_materia' => 'Introducción a Industrial',
				'nombre_material_oficial' => 'Introducción Industrial',
				'estado' => 1,
				'orden' => 1,
				'descripcion' => 'Fundamentos de ingeniería industrial',
				'id_parametro_economico' => $idParametroEconomico,
				'nro_creditos' => '4.00',
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
			[
				'sigla_materia' => 'ADM-100',
				'cod_pensum' => 'ADM-2024',
				'nombre_materia' => 'Fundamentos de Admin',
				'nombre_material_oficial' => 'Fundamentos Admin',
				'estado' => 1,
				'orden' => 1,
				'descripcion' => 'Conceptos básicos de administración',
				'id_parametro_economico' => $idParametroEconomico,
				'nro_creditos' => '3.00',
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now(),
			],
		];
		
		// Insertar materias usando updateOrInsert para evitar duplicados
		foreach ($materias as $materia) {
			DB::table('materia')->updateOrInsert(
				['sigla_materia' => $materia['sigla_materia'], 'cod_pensum' => $materia['cod_pensum']],
				$materia
			);
		}
	}
}
