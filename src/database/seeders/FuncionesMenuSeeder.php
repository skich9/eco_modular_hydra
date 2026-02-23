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
			// Cobros
			[
				'codigo' => 'cobros_gestionar',
				'nombre' => 'Gestionar Cobros',
				'descripcion' => 'Acceso a la gestión de cobros',
				'modulo' => 'Cobros',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Económico
			[
				'codigo' => 'economico_prorroga_mora',
				'nombre' => 'Prórroga Mora',
				'descripcion' => 'Acceso a la gestión de prórroga de mora',
				'modulo' => 'Económico',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Reportes
			[
				'codigo' => 'reportes_libro_diario',
				'nombre' => 'Libro Diario',
				'descripcion' => 'Acceso al reporte de Libro Diario',
				'modulo' => 'Reportes',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Reimpresión
			[
				'codigo' => 'reimpresion_facturacion_posterior',
				'nombre' => 'Facturación Posterior',
				'descripcion' => 'Acceso a la facturación posterior',
				'modulo' => 'Reimpresión',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Académico
			[
				'codigo' => 'academico_asignacion_becas',
				'nombre' => 'Asignación de Becas/Descuentos',
				'descripcion' => 'Acceso a la asignación de becas y descuentos',
				'modulo' => 'Académico',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// SIN
			[
				'codigo' => 'sin_estado_factura',
				'nombre' => 'Estado de Factura / Anulación',
				'descripcion' => 'Acceso al estado de facturas y anulaciones',
				'modulo' => 'SIN',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'sin_contingencias',
				'nombre' => 'Contingencias',
				'descripcion' => 'Acceso a la gestión de contingencias',
				'modulo' => 'SIN',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'sin_configuracion_punto_venta',
				'nombre' => 'Configuración Punto de Venta',
				'descripcion' => 'Acceso a la configuración de puntos de venta',
				'modulo' => 'SIN',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],

			// Configuración
			[
				'codigo' => 'configuracion_usuarios',
				'nombre' => 'Usuarios',
				'descripcion' => 'Acceso a la gestión de usuarios',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_roles',
				'nombre' => 'Roles',
				'descripcion' => 'Acceso a la gestión de roles',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_parametros',
				'nombre' => 'Parámetros de Sistema',
				'descripcion' => 'Acceso a los parámetros del sistema',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_descuentos',
				'nombre' => 'Configuración de Descuentos',
				'descripcion' => 'Acceso a la configuración de descuentos',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_costos',
				'nombre' => 'Configuración de Costos',
				'descripcion' => 'Acceso a la configuración de costos',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_costos_creditos',
				'nombre' => 'Configuración de Costos por Créditos',
				'descripcion' => 'Acceso a la configuración de costos por créditos',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_mora',
				'nombre' => 'Configuración de Moras',
				'descripcion' => 'Acceso a la configuración de moras',
				'modulo' => 'Configuración',
				'activo' => true,
				'created_at' => $now,
				'updated_at' => $now
			],
			[
				'codigo' => 'configuracion_generales',
				'nombre' => 'Configuraciones Generales',
				'descripcion' => 'Acceso a las configuraciones generales',
				'modulo' => 'Configuración',
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

		$this->command->info('Funciones insertadas correctamente (sin funciones de menú).');
	}
}
