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
            
            // Ruta para Académico (carreras -> pensums + materias)
            {
                path: 'academico/:codigo',
                loadComponent: () => import('./components/pages/academico/academico.component').then(m => m.AcademicoComponent)
            },
            
			// Rutas para parámetros del sistema
			{
				path: 'parametros',
				loadComponent: () => import('./components/pages/parametros-simple/parametros-simple.component').then(m => m.ParametrosSimpleComponent)
			},
			
			// Ruta por defecto
			{ path: '', redirectTo: 'dashboard', pathMatch: 'full' }
		]
	},
	
	// Redirección por defecto
	{ path: '', redirectTo: '/login', pathMatch: 'full' },
	{ path: '**', redirectTo: '/login' }
];
