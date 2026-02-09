<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RolFuncionSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		$now = Carbon::now();
		
		// Obtener IDs de roles
		$roles = DB::table('rol')->pluck('id_rol', 'nombre');
		
		// Obtener IDs de funciones
		$funciones = DB::table('funciones')->pluck('id_funcion', 'codigo');
		
		// Definir plantillas de roles
		$plantillas = [
			'Administrador' => [
				'cobros.ver',
				'cobros.crear',
				'cobros.editar',
				'cobros.eliminar',
				'config.ver',
				'config.editar',
				'sin.ver',
				'sin.configurar',
				'reportes.ver',
				'reportes.exportar',
				'usuarios.ver',
				'usuarios.crear',
				'usuarios.editar',
				'usuarios.eliminar',
				'usuarios.funciones',
				'roles.ver',
				'roles.crear',
				'roles.editar',
				'roles.eliminar',
				'roles.funciones',
				'academico.ver',
				'reimpresion.ver',
				'reimpresion.reimprimir',
			],
			'Cajero' => [
				'cobros.ver',
				'cobros.crear',
				'reportes.ver',
			],
			'Secretario' => [
				'cobros.ver',
				'cobros.crear',
				'reportes.ver',
				'academico.ver',
			],
		];
		
		$asignaciones = [];
		
		foreach ($plantillas as $rolNombre => $codigosFunciones) {
			if (!isset($roles[$rolNombre])) {
				$this->command->warn("⚠️  Rol '{$rolNombre}' no encontrado, saltando...");
				continue;
			}
			
			$idRol = $roles[$rolNombre];
			
			foreach ($codigosFunciones as $codigoFuncion) {
				if (!isset($funciones[$codigoFuncion])) {
					$this->command->warn("⚠️  Función '{$codigoFuncion}' no encontrada, saltando...");
					continue;
				}
				
				$asignaciones[] = [
					'id_rol' => $idRol,
					'id_funcion' => $funciones[$codigoFuncion],
					'created_at' => $now,
					'updated_at' => $now,
				];
			}
		}
		
		if (!empty($asignaciones)) {
			DB::table('rol_funcion')->insert($asignaciones);
			$this->command->info('✅ Se asignaron ' . count($asignaciones) . ' funciones a roles (plantillas)');
		}
	}
}
