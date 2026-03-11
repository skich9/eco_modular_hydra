<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FuncionesSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 */
	public function run(): void
	{
		$now = Carbon::now();
		
		$funciones = [
			// Módulo: Cobros
			[
				'codigo' => 'cobros.ver',
				'nombre' => 'Ver Cobros',
				'descripcion' => 'Permite visualizar la página de cobros y consultar cobros realizados',
				'modulo' => 'cobros',
				'icono' => 'fa-money-bill',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'cobros.crear',
				'nombre' => 'Crear Cobros',
				'descripcion' => 'Permite crear nuevos cobros y generar facturas',
				'modulo' => 'cobros',
				'icono' => 'fa-plus-circle',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'cobros.editar',
				'nombre' => 'Editar Cobros',
				'descripcion' => 'Permite modificar cobros existentes',
				'modulo' => 'cobros',
				'icono' => 'fa-edit',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'cobros.eliminar',
				'nombre' => 'Eliminar Cobros',
				'descripcion' => 'Permite anular o eliminar cobros',
				'modulo' => 'cobros',
				'icono' => 'fa-trash',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Configuración
			[
				'codigo' => 'config.ver',
				'nombre' => 'Ver Configuración',
				'descripcion' => 'Permite visualizar la configuración del sistema',
				'modulo' => 'configuracion',
				'icono' => 'fa-cog',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'config.editar',
				'nombre' => 'Editar Configuración',
				'descripcion' => 'Permite modificar la configuración del sistema',
				'modulo' => 'configuracion',
				'icono' => 'fa-wrench',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: SIN
			[
				'codigo' => 'sin.ver',
				'nombre' => 'Ver SIN',
				'descripcion' => 'Permite visualizar información del SIN',
				'modulo' => 'sin',
				'icono' => 'fa-file-invoice',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'sin.configurar',
				'nombre' => 'Configurar SIN',
				'descripcion' => 'Permite configurar puntos de venta, CUFD, CUIS del SIN',
				'modulo' => 'sin',
				'icono' => 'fa-tools',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Reportes
			[
				'codigo' => 'reportes.ver',
				'nombre' => 'Ver Reportes',
				'descripcion' => 'Permite visualizar reportes del sistema',
				'modulo' => 'reportes',
				'icono' => 'fa-chart-bar',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'reportes.exportar',
				'nombre' => 'Exportar Reportes',
				'descripcion' => 'Permite exportar reportes a PDF, Excel, etc.',
				'modulo' => 'reportes',
				'icono' => 'fa-download',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Usuarios
			[
				'codigo' => 'usuarios.ver',
				'nombre' => 'Ver Usuarios',
				'descripcion' => 'Permite visualizar la lista de usuarios',
				'modulo' => 'usuarios',
				'icono' => 'fa-users',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'usuarios.crear',
				'nombre' => 'Crear Usuarios',
				'descripcion' => 'Permite crear nuevos usuarios en el sistema',
				'modulo' => 'usuarios',
				'icono' => 'fa-user-plus',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'usuarios.editar',
				'nombre' => 'Editar Usuarios',
				'descripcion' => 'Permite modificar información de usuarios existentes',
				'modulo' => 'usuarios',
				'icono' => 'fa-user-edit',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'usuarios.eliminar',
				'nombre' => 'Eliminar Usuarios',
				'descripcion' => 'Permite desactivar o eliminar usuarios',
				'modulo' => 'usuarios',
				'icono' => 'fa-user-times',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'usuarios.funciones',
				'nombre' => 'Gestionar Funciones de Usuarios',
				'descripcion' => 'Permite asignar y quitar funciones a usuarios',
				'modulo' => 'usuarios',
				'icono' => 'fa-user-shield',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Roles
			[
				'codigo' => 'roles.ver',
				'nombre' => 'Ver Roles',
				'descripcion' => 'Permite visualizar la lista de roles',
				'modulo' => 'roles',
				'icono' => 'fa-user-tag',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'roles.crear',
				'nombre' => 'Crear Roles',
				'descripcion' => 'Permite crear nuevos roles en el sistema',
				'modulo' => 'roles',
				'icono' => 'fa-plus-square',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'roles.editar',
				'nombre' => 'Editar Roles',
				'descripcion' => 'Permite modificar roles existentes',
				'modulo' => 'roles',
				'icono' => 'fa-edit',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'roles.eliminar',
				'nombre' => 'Eliminar Roles',
				'descripcion' => 'Permite eliminar roles del sistema',
				'modulo' => 'roles',
				'icono' => 'fa-trash-alt',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'roles.funciones',
				'nombre' => 'Gestionar Funciones de Roles',
				'descripcion' => 'Permite asignar funciones a roles (plantillas)',
				'modulo' => 'roles',
				'icono' => 'fa-tasks',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Académico
			[
				'codigo' => 'academico.ver',
				'nombre' => 'Ver Académico',
				'descripcion' => 'Permite visualizar información académica',
				'modulo' => 'academico',
				'icono' => 'fa-graduation-cap',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			
			// Módulo: Reimpresión
			[
				'codigo' => 'reimpresion.ver',
				'nombre' => 'Ver Reimpresión',
				'descripcion' => 'Permite visualizar la página de reimpresión',
				'modulo' => 'reimpresion',
				'icono' => 'fa-print',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'codigo' => 'reimpresion.reimprimir',
				'nombre' => 'Reimprimir Facturas',
				'descripcion' => 'Permite reimprimir facturas existentes',
				'modulo' => 'reimpresion',
				'icono' => 'fa-redo',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now,
			],
		];
		
		DB::table('funciones')->insert($funciones);
		
		$this->command->info('✅ Se insertaron ' . count($funciones) . ' funciones básicas');
	}
}
