import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError, switchMap } from 'rxjs';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
	const authService = inject(AuthService);
	const router = inject(Router);
	const token = authService.getToken();

	let clonedReq = req;
	const hasAuthHeader = req.headers.has('Authorization');
	if (token && !hasAuthHeader) {
		clonedReq = req.clone({
			headers: req.headers.set('Authorization', `Bearer ${token}`)
		});
	}

	// Si hay token y NO es la ruta de login o refresh-token, verificar si necesita refresh
	const isLoginRequest = req.url.includes('/login');
	const isRefreshRequest = req.url.includes('/refresh-token');
	const needsRefresh = token && !isLoginRequest && !isRefreshRequest && authService.shouldRefreshToken();

	if (needsRefresh) {
		return authService.refreshToken().pipe(
			switchMap(() => next(clonedReq)),
			catchError((refreshError: HttpErrorResponse) => {
				// Si el refresh falla (token expirado), continuar con la petición original
				// El error 401/419 será manejado por el catchError principal
				console.warn('[AuthInterceptor] Error al refrescar token, continuando con petición:', refreshError.status);
				return next(clonedReq);
			})
		);
	}

	// Manejar la respuesta y capturar errores
	return next(clonedReq).pipe(
		catchError((error: HttpErrorResponse) => {
			const hasSessionToken = !!authService.getToken();
			// Si es error 401 (No autorizado) o 419 (Token expirado en Laravel)
			if ((error.status === 401 || error.status === 419) && hasSessionToken) {
				console.warn('[AuthInterceptor] Token inválido o expirado (401/419). Cerrando sesión...');

				// Limpiar sesión
				authService.clearSession();

				// Redirigir al login
				router.navigate(['/login'], {
					queryParams: {
						sessionExpired: 'true',
						returnUrl: router.url
					}
				});
			}

			// Si es error 403 (Forbidden - sin permisos)
			if (error.status === 403) {
				console.warn('[AuthInterceptor] Acceso denegado (403)');
				router.navigate(['/dashboard'], {
					queryParams: {
						error: 'forbidden',
						message: 'No tienes permisos para realizar esta acción'
					}
				});
			}

			// Propagar el error para que los componentes también puedan manejarlo
			return throwError(() => error);
		})
	);
};
