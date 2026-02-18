import { Routes } from '@angular/router';
import { ApiTestComponent } from './components/api-test/api-test.component';
import { LoginComponent } from './components/auth/login/login.component';
import { LayoutComponent } from './components/shared/layout/layout.component';
import { UsuariosListComponent } from './components/usuarios/usuarios-list/usuarios-list.component';
import { UsuarioFormComponent } from './components/usuarios/usuario-form/usuario-form.component';
import { MateriasListComponent } from './components/materias/materias-list/materias-list.component';
import { MateriaFormComponent } from './components/materias/materia-form/materia-form.component';
import { RolesListComponent } from './components/roles/roles-list/roles-list.component';
import { RolFormComponent } from './components/roles/rol-form/rol-form.component';
import { authGuard, publicOnlyGuard } from './guards/auth.guard';
import { permissionGuard } from './guards/permission.guard';

export const routes: Routes = [
	// Rutas públicas
	{
		path: 'login',
		component: LoginComponent,
		canActivate: [publicOnlyGuard]
	},
	{ path: 'api-test', component: ApiTestComponent },

	// Rutas protegidas (requieren autenticación)
	{
		path: '',
		component: LayoutComponent,
		canActivate: [authGuard],
		children: [
			{
				path: 'dashboard',
				loadComponent: () => import('./components/pages/dashboard/dashboard.component').then(m => m.DashboardComponent)
			},

			// Rutas para usuarios
			{
				path: 'usuarios',
				component: UsuariosListComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_usuarios' }
			},
			{
				path: 'usuarios/nuevo',
				component: UsuarioFormComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_usuarios' }
			},
			{
				path: 'usuarios/editar/:id',
				component: UsuarioFormComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_usuarios' }
			},

			// Rutas para materias
			{
				path: 'materias',
				component: MateriasListComponent,
				canActivate: [permissionGuard],
				data: { requiredModule: 'Configuración' }
			},
			{
				path: 'materias/nuevo',
				component: MateriaFormComponent,
				canActivate: [permissionGuard],
				data: { requiredModule: 'Configuración' }
			},
			{
				path: 'materias/editar/:sigla/:pensum',
				component: MateriaFormComponent,
				canActivate: [permissionGuard],
				data: { requiredModule: 'Configuración' }
			},

			// Rutas para roles
			{
				path: 'roles',
				component: RolesListComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_roles' }
			},
			{
				path: 'roles/nuevo',
				component: RolFormComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_roles' }
			},
			{
				path: 'roles/editar/:id',
				component: RolFormComponent,
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_roles' }
			},

			// Ruta para funciones de usuario
			{
				path: 'mis-funciones',
				loadComponent: () => import('./components/pages/usuario-funciones/usuario-funciones.component').then(m => m.UsuarioFuncionesComponent)
			},

            // Ruta para Académico: página específica (debe ir antes de la parametrizada)
            {
                path: 'academico/asignacion-becas-descuentos',
                loadComponent: () => import('./components/pages/academico/asignacion-becas-descuentos/asignacion-becas-descuentos.component')
                    .then(m => m.AsignacionBecasDescuentosComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'academico_asignacion_becas' }
            },
            // Ruta para Académico (carreras -> pensums + materias)
            {
                path: 'academico/:codigo',
                loadComponent: () => import('./components/pages/academico/academico.component').then(m => m.AcademicoComponent),
				canActivate: [permissionGuard],
				data: { requiredModule: 'Académico' }
            },

			// Ruta para descuentos
			{
				path: 'descuentos',
				loadComponent: () => import('./components/pages/descuentos-config/descuentos-config.component').then(m => m.DescuentosConfigComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_descuentos' }
			},

			// Rutas para parámetros del sistema
			{
				path: 'parametros',
				loadComponent: () => import('./components/pages/parametros-simple/parametros-simple.component').then(m => m.ParametrosSimpleComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_parametros' }
			},
			// Ruta para Configuración de Costos
			{
				path: 'costos',
				loadComponent: () => import('./components/pages/costos-config/costos-config.component').then(m => m.CostosConfigComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_costos' }
			},
			// Ruta para Configuración de Costos por Créditos
			{
				path: 'costos-creditos',
				loadComponent: () => import('./components/pages/costos-creditos-config/costos-creditos-config.component').then(m => m.CostosCreditosConfigComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_costos_creditos' }
			},
			// Ruta para Cobros (resumen y registro por lote)
			{
				path: 'cobros',
				loadComponent: () => import('./components/pages/cobros/cobros.component').then(m => m.CobrosComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'cobros_gestionar' }
			},
			// Reimpresión: Facturación posterior
			{
				path: 'reimpresion/facturacion-posterior',
				loadComponent: () => import('./components/pages/reimpresion/facturacion-posterior/facturacion-posterior.component').then(m => m.FacturacionPosteriorComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'reimpresion_facturacion_posterior' }
			},
			// Ruta para Configuraciones Generales
			{
				path: 'configuraciones-generales',
				loadComponent: () => import('./components/pages/configuraciones-generales/configuraciones-generales.component').then(m => m.ConfiguracionesGeneralesComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_generales' }
			},
			// Ruta para Configuración de Moras
			{
				path: 'configuracion-mora',
				loadComponent: () => import('./components/pages/configuracion-mora/configuracion-mora.component').then(m => m.ConfiguracionMoraComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'configuracion_mora' }
			},

            // SIN: Estado de Factura / Anulación
            {
                path: 'sin/estado-factura',
                loadComponent: () => import('./components/pages/sin/estado-factura/estado-factura.component').then(m => m.EstadoFacturaComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'sin_estado_factura' }
            },
            // SIN: Contingencias
            {
                path: 'sin/contingencias',
                loadComponent: () => import('./components/pages/sin/contingencias/contingencias.component').then(m => m.ContingenciasComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'sin_contingencias' }
            },
            // SIN: Configuración Punto de Venta
            {
                path: 'sin/configuracion-punto-venta',
                loadComponent: () => import('./components/pages/sin/configuracion-punto-venta/configuracion-punto-venta.component').then(m => m.ConfiguracionPuntoVentaComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'sin_configuracion_punto_venta' }
            },

            // Reportes
            {
                path: 'reportes/libro-diario',
                loadComponent: () => import('./components/pages/reportes/libro-diario/libro-diario.component').then(m => m.LibroDiarioComponent),
				canActivate: [permissionGuard],
				data: { requiredPermission: 'reportes_libro_diario' }
            },

			// Ruta por defecto
			{ path: '', redirectTo: 'dashboard', pathMatch: 'full' }
		]
	},

	// Redirección por defecto
	{ path: '', redirectTo: '/login', pathMatch: 'full' },
	{ path: '**', redirectTo: '/login' }
];
