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
			{ path: 'usuarios', component: UsuariosListComponent },
			{ path: 'usuarios/nuevo', component: UsuarioFormComponent },
			{ path: 'usuarios/editar/:id', component: UsuarioFormComponent },
			
			// Rutas para materias
			{ path: 'materias', component: MateriasListComponent },
			{ path: 'materias/nuevo', component: MateriaFormComponent },
			{ path: 'materias/editar/:sigla/:pensum', component: MateriaFormComponent },
			
			// Rutas para roles
			{ path: 'roles', component: RolesListComponent },
			{ path: 'roles/nuevo', component: RolFormComponent },
			{ path: 'roles/editar/:id', component: RolFormComponent },
            
            // Ruta para Académico: página específica (debe ir antes de la parametrizada)
            {
                path: 'academico/asignacion-becas-descuentos',
                loadComponent: () => import('./components/pages/academico/asignacion-becas-descuentos/asignacion-becas-descuentos.component')
                    .then(m => m.AsignacionBecasDescuentosComponent)
            },
            // Ruta para Académico (carreras -> pensums + materias)
            {
                path: 'academico/:codigo',
                loadComponent: () => import('./components/pages/academico/academico.component').then(m => m.AcademicoComponent)
            },
            
			// Ruta para descuentos
			{
				path: 'descuentos',
				loadComponent: () => import('./components/pages/descuentos-config/descuentos-config.component').then(m => m.DescuentosConfigComponent)
			},
			
			// Rutas para parámetros del sistema
			{
				path: 'parametros',
				loadComponent: () => import('./components/pages/parametros-simple/parametros-simple.component').then(m => m.ParametrosSimpleComponent)
			},
			// Ruta para Configuración de Costos
			{
				path: 'costos',
				loadComponent: () => import('./components/pages/costos-config/costos-config.component').then(m => m.CostosConfigComponent)
			},
			// Ruta para Configuración de Costos por Créditos
			{
				path: 'costos-creditos',
				loadComponent: () => import('./components/pages/costos-creditos-config/costos-creditos-config.component').then(m => m.CostosCreditosConfigComponent)
			},
			// Ruta para Cobros (resumen y registro por lote)
			{
				path: 'cobros',
				loadComponent: () => import('./components/pages/cobros/cobros.component').then(m => m.CobrosComponent)
			},
			// Reimpresión: Facturación posterior
			{
				path: 'reimpresion/facturacion-posterior',
				loadComponent: () => import('./components/pages/reimpresion/facturacion-posterior/facturacion-posterior.component').then(m => m.FacturacionPosteriorComponent)
			},
			// Ruta para Configuraciones Generales
			{
				path: 'configuraciones-generales',
				loadComponent: () => import('./components/pages/configuraciones-generales/configuraciones-generales.component').then(m => m.ConfiguracionesGeneralesComponent)
			},

            // SIN: Estado de Factura / Anulación
            {
                path: 'sin/estado-factura',
                loadComponent: () => import('./components/pages/sin/estado-factura/estado-factura.component').then(m => m.EstadoFacturaComponent)
            },
            // SIN: Contingencias
            {
                path: 'sin/contingencias',
                loadComponent: () => import('./components/pages/sin/contingencias/contingencias.component').then(m => m.ContingenciasComponent)
            },
			
			// Ruta por defecto
			{ path: '', redirectTo: 'dashboard', pathMatch: 'full' }
		]
	},
	
	// Redirección por defecto
	{ path: '', redirectTo: '/login', pathMatch: 'full' },
	{ path: '**', redirectTo: '/login' }
];
