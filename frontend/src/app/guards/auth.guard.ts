import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';

export const authGuard: CanActivateFn = (route, state) => {
	const authService = inject(AuthService);
	const router = inject(Router);
	
	if (authService.isAuthenticated()) {
		return true;
	}
	
	// Redirigir al login si no está autenticado
	return router.parseUrl('/login');
};

export const publicOnlyGuard: CanActivateFn = (route, state) => {
	const authService = inject(AuthService);
	const router = inject(Router);
	
	if (!authService.isAuthenticated()) {
		return true;
	}
	
	// Si ya está autenticado, redirigir al dashboard
	return router.parseUrl('/dashboard');
};
