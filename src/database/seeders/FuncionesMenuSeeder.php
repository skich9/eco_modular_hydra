<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FuncionesMenuSeeder extends Seeder
{
	/**
	 * Run the database seeder.
	 * Inserta las funciones correspondientes a los menús de navegación del sistema
	 */
	public function run()
	{
		$now = Carbon::now();

		$funciones = [
			// Menú Cobros
			[
				'codigo' => 'menu_cobros',
				'nombre' => 'Menú Cobros',
				'descripcion' => 'Acceso al menú principal de Cobros',
				'modulo' => 'Cobros',
				'icono' => 'fa-money-bill-wave',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'cobros_gestionar',
				'nombre' => 'Gestionar Cobros',
				'descripcion' => 'Acceso a la gestión de cobros',
				'modulo' => 'Cobros',
				'icono' => 'fa-cash-register',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Menú Reportes
			[
				'codigo' => 'menu_reportes',
				'nombre' => 'Menú Reportes',
				'descripcion' => 'Acceso al menú principal de Reportes',
				'modulo' => 'Reportes',
				'icono' => 'fa-chart-bar',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'reportes_libro_diario',
				'nombre' => 'Libro Diario',
				'descripcion' => 'Acceso al reporte de Libro Diario',
				'modulo' => 'Reportes',
				'icono' => 'fa-book',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Menú Reimpresión
			[
				'codigo' => 'menu_reimpresion',
				'nombre' => 'Menú Reimpresión',
				'descripcion' => 'Acceso al menú principal de Reimpresión',
				'modulo' => 'Reimpresión',
				'icono' => 'fa-print',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'reimpresion_facturacion_posterior',
				'nombre' => 'Facturación Posterior',
				'descripcion' => 'Acceso a la facturación posterior',
				'modulo' => 'Reimpresión',
				'icono' => 'fa-file-invoice-dollar',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Menú Académico
			[
				'codigo' => 'menu_academico',
				'nombre' => 'Menú Académico',
				'descripcion' => 'Acceso al menú principal Académico',
				'modulo' => 'Académico',
				'icono' => 'fa-graduation-cap',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'academico_asignacion_becas',
				'nombre' => 'Asignación de Becas/Descuentos',
				'descripcion' => 'Acceso a la asignación de becas y descuentos',
				'modulo' => 'Académico',
				'icono' => 'fa-percent',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Menú SIN
			[
				'codigo' => 'menu_sin',
				'nombre' => 'Menú SIN',
				'descripcion' => 'Acceso al menú principal de SIN',
				'modulo' => 'SIN',
				'icono' => 'fa-file-invoice',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'sin_estado_factura',
				'nombre' => 'Estado de Factura / Anulación',
				'descripcion' => 'Acceso al estado de facturas y anulaciones',
				'modulo' => 'SIN',
				'icono' => 'fa-file-signature',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'sin_contingencias',
				'nombre' => 'Contingencias',
				'descripcion' => 'Acceso a la gestión de contingencias',
				'modulo' => 'SIN',
				'icono' => 'fa-exclamation-triangle',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'sin_configuracion_punto_venta',
				'nombre' => 'Configuración Punto de Venta',
				'descripcion' => 'Acceso a la configuración de puntos de venta',
				'modulo' => 'SIN',
				'icono' => 'fa-store',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Menú Configuración
			[
				'codigo' => 'menu_configuracion',
				'nombre' => 'Menú Configuración',
				'descripcion' => 'Acceso al menú principal de Configuración',
				'modulo' => 'Configuración',
				'icono' => 'fa-cog',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_usuarios',
				'nombre' => 'Usuarios',
				'descripcion' => 'Acceso a la gestión de usuarios',
				'modulo' => 'Configuración',
				'icono' => 'fa-users',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_roles',
				'nombre' => 'Roles',
				'descripcion' => 'Acceso a la gestión de roles',
				'modulo' => 'Configuración',
				'icono' => 'fa-user-shield',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_parametros',
				'nombre' => 'Parámetros de Sistema',
				'descripcion' => 'Acceso a los parámetros del sistema',
				'modulo' => 'Configuración',
				'icono' => 'fa-sliders-h',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_descuentos',
				'nombre' => 'Configuración de Descuentos',
				'descripcion' => 'Acceso a la configuración de descuentos',
				'modulo' => 'Configuración',
				'icono' => 'fa-percent',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_costos',
				'nombre' => 'Configuración de Costos',
				'descripcion' => 'Acceso a la configuración de costos',
				'modulo' => 'Configuración',
				'icono' => 'fa-coins',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_costos_creditos',
				'nombre' => 'Configuración de Costos por Créditos',
				'descripcion' => 'Acceso a la configuración de costos por créditos',
				'modulo' => 'Configuración',
				'icono' => 'fa-calculator',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_generales',
				'nombre' => 'Configuraciones Generales',
				'descripcion' => 'Acceso a las configuraciones generales',
				'modulo' => 'Configuración',
				'icono' => 'fa-cogs',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			]
		];

		// Insertar funciones solo si no existen (basado en código único)
		foreach ($funciones as $funcion) {
			DB::table('funciones')->updateOrInsert(
				['codigo' => $funcion['codigo']],
				$funcion
			);
		}

		$this->command->info('Funciones de menú insertadas correctamente.');
	}
}
