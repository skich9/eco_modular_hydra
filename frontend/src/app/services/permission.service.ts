import { Injectable } from '@angular/core';
import { AuthService } from './auth.service';
import { UsuarioFuncion } from '../models/usuario.model';

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

	hasPermission(codigo: string): boolean {
		return this.funciones.some(f => f.codigo === codigo);
	}

	hasAnyPermission(codigos: string[]): boolean {
		return codigos.some(codigo => this.hasPermission(codigo));
	}

	hasAllPermissions(codigos: string[]): boolean {
		return codigos.every(codigo => this.hasPermission(codigo));
	}

	getFuncionesPorModulo(modulo: string): UsuarioFuncion[] {
		return this.funciones.filter(f => f.modulo === modulo);
	}

	getAllFunciones(): UsuarioFuncion[] {
		return this.funciones;
	}
}
