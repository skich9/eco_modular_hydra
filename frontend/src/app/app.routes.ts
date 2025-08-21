import { Routes } from '@angular/router';
import { ApiTestComponent } from './components/api-test/api-test.component';
import { LoginComponent } from './components/auth/login/login.component';
import { DashboardComponent } from './components/dashboard/dashboard.component';
import { LayoutComponent } from './components/shared/layout/layout.component';
import { UsuariosListComponent } from './components/usuarios/usuarios-list/usuarios-list.component';
import { UsuarioFormComponent } from './components/usuarios/usuario-form/usuario-form.component';
import { MateriasListComponent } from './components/materias/materias-list/materias-list.component';
import { MateriaFormComponent } from './components/materias/materia-form/materia-form.component';
import { RolesListComponent } from './components/roles/roles-list/roles-list.component';
import { RolFormComponent } from './components/roles/rol-form/rol-form.component';
import { ParametrosSistemaFormComponent } from './components/parametros/parametros-sistema-form/parametros-sistema-form.component';
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
			{ path: 'dashboard', component: DashboardComponent },
			
			// Rutas para usuarios
			{ path: 'usuarios', component: UsuariosListComponent },
			{ path: 'usuarios/nuevo', component: UsuarioFormComponent },
			{ path: 'usuarios/editar/:id', component: UsuarioFormComponent },
			
			// Rutas para materias
			{ path: 'materias', component: MateriasListComponent },
			{ path: 'materias/nuevo', component: MateriaFormComponent },
			{ path: 'materias/editar/:sigla', component: MateriaFormComponent },
			
			// Rutas para roles
			{ path: 'roles', component: RolesListComponent },
			{ path: 'roles/nuevo', component: RolFormComponent },
			{ path: 'roles/editar/:id', component: RolFormComponent },
			
			// Rutas para parámetros del sistema
			{ path: 'parametros', component: ParametrosSistemaFormComponent },
			{ path: 'parametros/nuevo', component: ParametrosSistemaFormComponent },
			{ path: 'parametros/editar/:id', component: ParametrosSistemaFormComponent },
			
			// Ruta por defecto
			{ path: '', redirectTo: 'dashboard', pathMatch: 'full' }
		]
	},
	
	// Redirección por defecto
	{ path: '', redirectTo: '/login', pathMatch: 'full' },
	{ path: '**', redirectTo: '/login' }
];
