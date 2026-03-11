import { CanActivateFn, Router, ActivatedRouteSnapshot } from '@angular/router';
import { inject } from '@angular/core';
import { PermissionService } from '../services/permission.service';

/**
 * Guard para proteger rutas basándose en permisos/funciones del usuario
 * Uso: canActivate: [permissionGuard], data: { requiredPermission: 'codigo_funcion' }
 */
export const permissionGuard: CanActivateFn = (route: ActivatedRouteSnapshot) => {
	const permissionService = inject(PermissionService);
	const router = inject(Router);

	// Obtener el permiso requerido desde la configuración de la ruta
	const requiredPermission = route.data['requiredPermission'] as string;
	const requiredModule = route.data['requiredModule'] as string;
	const anyPermissions = route.data['anyPermissions'] as string[];

	// Si no hay requisitos de permisos, permitir acceso
	if (!requiredPermission && !requiredModule && !anyPermissions) {
		console.warn('Ruta sin requisitos de permisos configurados:', route.routeConfig?.path);
		return true;
	}

	// Verificar permiso específico
	if (requiredPermission && permissionService.hasPermission(requiredPermission)) {
		return true;
	}

	// Verificar módulo (al menos una función del módulo)
	if (requiredModule && permissionService.hasAnyFunctionFromModule(requiredModule)) {
		return true;
	}

	// Verificar si tiene alguno de los permisos de la lista
	if (anyPermissions && permissionService.hasAnyPermission(anyPermissions)) {
		return true;
	}

	// Si no tiene permisos, redirigir al dashboard con mensaje
	console.warn('Acceso denegado a:', route.routeConfig?.path);
	router.navigate(['/dashboard'], {
		queryParams: { 
			error: 'no_permission',
			message: 'No tienes permisos para acceder a esta sección'
		}
	});
	return false;
};
