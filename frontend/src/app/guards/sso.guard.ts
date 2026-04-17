import { CanActivateFn, Router } from '@angular/router';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';

/**
 * Guard para rutas SSO: permite acceso si hay un sso_token válido en query params
 * Si el token es válido, se autentica automáticamente sin necesidad de estar logueado previamente
 */
export const ssoGuard: CanActivateFn = (route, state) => {
	const authService = inject(AuthService);
	const router = inject(Router);

	// Verificar si ya está autenticado
	if (authService.isAuthenticated()) {
		console.info('[SSO Guard] Ya autenticado, permitiendo acceso');
		return true;
	}

	// Buscar sso_token en la URL actual (window.location.search)
	const urlParams = new URLSearchParams(window.location.search);
	const ssoToken = urlParams.get('sso_token');

	if (!ssoToken) {
		// Sin sso_token y sin autenticación: bloquear y redirigir a login
		console.info('[SSO Guard] No sso_token encontrado, redirigiendo a login');
		return router.createUrlTree(['/login'], {
			queryParams: {
				returnUrl: state.url
			}
		});
	}

	// Si hay sso_token, permitir que el componente lo valide internamente
	console.info('[SSO Guard] Token SSO detectado, permitiendo acceso a componente', {
		ssoTokenLength: ssoToken.length,
		path: state.url
	});
	return true;
};
