<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesFuncionesActualesSeeder extends Seeder
{
	public function run()
	{
		$roles = [
			[
				'id_rol' => 1,
				'nombre' => 'Administrador',
				'descripcion' => 'El superusuario que esta acargo de todo lo qeu se puede hacer ene le sistema',
				'estado' => 1,
				'created_at' => '2025-09-03 20:05:08',
				'updated_at' => '2026-02-10 12:41:13',
			],
			[
				'id_rol' => 2,
				'nombre' => 'Secretaria',
				'descripcion' => 'Encagarda de hacer los cobros a los clientes',
				'estado' => 1,
				'created_at' => '2025-09-03 20:05:08',
				'updated_at' => '2025-09-03 20:05:08',
			],
			[
				'id_rol' => 3,
				'nombre' => 'Docente',
				'descripcion' => 'Encargado de impartir clase en su aula de electronica o mecanica',
				'estado' => 1,
				'created_at' => '2025-09-03 20:05:08',
				'updated_at' => '2025-09-03 20:05:08',
			],
			[
				'id_rol' => 5,
				'nombre' => 'Contador',
				'descripcion' => 'Encargado de hacer la contabilidad',
				'estado' => 1,
				'created_at' => '2025-09-17 19:44:01',
				'updated_at' => '2025-09-17 19:44:01',
			],
			[
				'id_rol' => 6,
				'nombre' => 'Sistemas',
				'descripcion' => 'encargado de sisitemas',
				'estado' => 1,
				'created_at' => '2025-12-24 12:54:04',
				'updated_at' => '2025-12-24 12:54:04',
			],
			[
				'id_rol' => 7,
				'nombre' => 'Jefatura de carrera',
				'descripcion' => 'Encargado de los estudiantes y tramites organizacion y logistica',
				'estado' => 1,
				'created_at' => '2025-12-24 12:54:04',
				'updated_at' => '2025-12-24 12:54:04',
			],
			[
				'id_rol' => 8,
				'nombre' => 'Academico',
				'descripcion' => 'Encargados de la titulacion  historial academico ',
				'estado' => 1,
				'created_at' => '2025-12-24 12:54:04',
				'updated_at' => '2025-12-24 12:54:04',
			],
		];

		$funciones = [
			[
				'id_funcion' => 55,
				'codigo' => 'cobros_gestionar',
				'nombre' => 'Gestionar Cobros',
				'descripcion' => 'Acceso a la gestión de cobros',
				'modulo' => 'Cobros',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 57,
				'codigo' => 'reportes_libro_diario',
				'nombre' => 'Libro Diario',
				'descripcion' => 'Acceso al reporte de Libro Diario',
				'modulo' => 'Reportes',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 59,
				'codigo' => 'reimpresion_facturacion_posterior',
				'nombre' => 'Facturación Posterior',
				'descripcion' => 'Acceso a la facturación posterior',
				'modulo' => 'Reimpresión',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 61,
				'codigo' => 'academico_asignacion_becas',
				'nombre' => 'Asignación de Becas/Descuentos',
				'descripcion' => 'Acceso a la asignación de becas y descuentos',
				'modulo' => 'Académico',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 63,
				'codigo' => 'sin_estado_factura',
				'nombre' => 'Estado de Factura / Anulación',
				'descripcion' => 'Acceso al estado de facturas y anulaciones',
				'modulo' => 'SIN',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 64,
				'codigo' => 'sin_contingencias',
				'nombre' => 'Contingencias',
				'descripcion' => 'Acceso a la gestión de contingencias',
				'modulo' => 'SIN',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 65,
				'codigo' => 'sin_configuracion_punto_venta',
				'nombre' => 'Configuración Punto de Venta',
				'descripcion' => 'Acceso a la configuración de puntos de venta',
				'modulo' => 'SIN',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 67,
				'codigo' => 'configuracion_usuarios',
				'nombre' => 'Usuarios',
				'descripcion' => 'Acceso a la gestión de usuarios',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 68,
				'codigo' => 'configuracion_roles',
				'nombre' => 'Roles',
				'descripcion' => 'Acceso a la gestión de roles',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 69,
				'codigo' => 'configuracion_parametros',
				'nombre' => 'Parámetros de Sistema',
				'descripcion' => 'Acceso a los parámetros del sistema',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 70,
				'codigo' => 'configuracion_descuentos',
				'nombre' => 'Configuración de Descuentos',
				'descripcion' => 'Acceso a la configuración de descuentos',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 71,
				'codigo' => 'configuracion_costos',
				'nombre' => 'Configuración de Costos',
				'descripcion' => 'Acceso a la configuración de costos',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 72,
				'codigo' => 'configuracion_costos_creditos',
				'nombre' => 'Configuración de Costos por Créditos',
				'descripcion' => 'Acceso a la configuración de costos por créditos',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 73,
				'codigo' => 'configuracion_generales',
				'nombre' => 'Configuraciones Generales',
				'descripcion' => 'Acceso a las configuraciones generales',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-10 15:18:27',
				'updated_at' => '2026-02-10 15:18:27',
			],
			[
				'id_funcion' => 74,
				'codigo' => 'configuracion_mora',
				'nombre' => 'Configuración de Moras',
				'descripcion' => 'Permite configurar los montos y tipos de cálculo de moras para pagos atrasados',
				'modulo' => 'Configuración',
				'activo' => 1,
				'created_at' => '2026-02-18 11:05:41',
				'updated_at' => '2026-02-18 11:05:48',
			],
			[
				'id_funcion' => 75,
				'codigo' => 'economico_prorroga_mora',
				'nombre' => 'Prórroga Mora',
				'descripcion' => 'Permite crear prorrogas en las multas',
				'modulo' => 'Económico',
				'activo' => 1,
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_funcion' => 76,
				'codigo' => 'economico_descuento_mora',
				'nombre' => 'Descuento Mora',
				'descripcion' => 'Acceso a la gestión de descuentos de mora',
				'modulo' => 'Económico',
				'activo' => 1,
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_funcion' => 77,
				'codigo' => 'economico_otros_ingresos',
				'nombre' => 'Otros ingresos (ventas)',
				'descripcion' => 'Registro de otros ingresos no acad?micos',
				'modulo' => 'Cobros',
				'activo' => 1,
				'created_at' => null,
				'updated_at' => null,
			],
			[
				'id_funcion' => 78,
				'codigo' => 'economico_mod_otros_ingresos',
				'nombre' => 'Modificar / eliminar otros ingresos',
				'descripcion' => 'B?squeda, edici?n y eliminaci?n de otros ingresos',
				'modulo' => 'Cobros',
				'activo' => 1,
				'created_at' => null,
				'updated_at' => null,
			],
		];

		$rolFunciones = [
			['id_rol_funcion' => 36, 'id_rol' => 1, 'id_funcion' => 55, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 38, 'id_rol' => 1, 'id_funcion' => 57, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 40, 'id_rol' => 1, 'id_funcion' => 59, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 42, 'id_rol' => 1, 'id_funcion' => 61, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 44, 'id_rol' => 1, 'id_funcion' => 63, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 45, 'id_rol' => 1, 'id_funcion' => 64, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 46, 'id_rol' => 1, 'id_funcion' => 65, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 48, 'id_rol' => 1, 'id_funcion' => 67, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 49, 'id_rol' => 1, 'id_funcion' => 68, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 50, 'id_rol' => 1, 'id_funcion' => 69, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 51, 'id_rol' => 1, 'id_funcion' => 70, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 52, 'id_rol' => 1, 'id_funcion' => 71, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 53, 'id_rol' => 1, 'id_funcion' => 72, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 54, 'id_rol' => 1, 'id_funcion' => 73, 'created_at' => '2026-02-10 15:04:48', 'updated_at' => '2026-02-10 15:04:48'],
			['id_rol_funcion' => 55, 'id_rol' => 1, 'id_funcion' => 74, 'created_at' => '2026-02-10 19:38:03', 'updated_at' => '2026-02-10 19:38:03'],
			['id_rol_funcion' => 56, 'id_rol' => 1, 'id_funcion' => 75, 'created_at' => '2026-02-10 19:38:03', 'updated_at' => '2026-02-10 19:38:03'],
			['id_rol_funcion' => 57, 'id_rol' => 1, 'id_funcion' => 76, 'created_at' => '2026-02-10 19:38:03', 'updated_at' => '2026-02-10 19:38:03'],
			['id_rol_funcion' => 58, 'id_rol' => 2, 'id_funcion' => 77, 'created_at' => '2026-02-10 22:52:25', 'updated_at' => '2026-02-10 22:52:25'],
			['id_rol_funcion' => 59, 'id_rol' => 2, 'id_funcion' => 78, 'created_at' => '2026-02-10 22:52:25', 'updated_at' => '2026-02-10 22:52:25'],
		];

		DB::transaction(function () use ($roles, $funciones, $rolFunciones) {
			foreach ($roles as $r) {
				DB::table('rol')->updateOrInsert(
					['id_rol' => $r['id_rol']],
					$r
				);
			}

			foreach ($funciones as $f) {
				DB::table('funciones')->updateOrInsert(
					['id_funcion' => $f['id_funcion']],
					$f
				);
			}

			foreach ($rolFunciones as $rf) {
				DB::table('rol_funcion')->updateOrInsert(
					['id_rol' => $rf['id_rol'], 'id_funcion' => $rf['id_funcion']],
					$rf
				);
			}
		});
	}
}
