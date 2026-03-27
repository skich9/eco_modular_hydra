import { Injectable } from '@angular/core';
import { AuthService } from './auth.service';
import { Usuario, UsuarioFuncion } from '../models/usuario.model';

@Injectable({
	providedIn: 'root'
})
export class PermissionService {
	private funciones: UsuarioFuncion[] = [];

	constructor(private authService: AuthService) {
		this.authService.currentUser$.subscribe(user => {
			this.funciones = user?.funciones || [];
		});
	}

	/**
	 * Administrador del sistema (convención: id_rol = 1 en UsuarioSeeder, o nombre de rol).
	 * Evita que nuevas funciones de menú queden ocultas hasta re-asignar permisos en BD.
	 */
	private hasAdminBypass(user: Usuario | null): boolean {
		if (!user) {
			return false;
		}
		if (user.id_rol === 1) {
			return true;
		}
		const n = (user.rol?.nombre || '').toLowerCase();
		return n.includes('administrador') || n.includes('admin principal');
	}

	hasPermission(codigo: string): boolean {
		const user = this.authService.getCurrentUser();
		if (this.hasAdminBypass(user)) {
			return true;
		}
		return this.funciones.some(f => f.codigo === codigo);
	}

	hasAnyPermission(codigos: string[]): boolean {
		const user = this.authService.getCurrentUser();
		if (this.hasAdminBypass(user)) {
			return true;
		}
		return codigos.some(codigo => this.hasPermission(codigo));
	}

	hasAllPermissions(codigos: string[]): boolean {
		const user = this.authService.getCurrentUser();
		if (this.hasAdminBypass(user)) {
			return true;
		}
		return codigos.every(codigo => this.hasPermission(codigo));
	}

	getFuncionesPorModulo(modulo: string): UsuarioFuncion[] {
		return this.funciones.filter(f => f.modulo === modulo);
	}

	hasAnyFunctionFromModule(modulo: string): boolean {
		const user = this.authService.getCurrentUser();
		if (this.hasAdminBypass(user)) {
			return true;
		}
		return this.funciones.some(f => f.modulo === modulo);
	}

	getAllFunciones(): UsuarioFuncion[] {
		return this.funciones;
	}
}
