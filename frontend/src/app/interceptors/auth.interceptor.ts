import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
	const authService = inject(AuthService);
	const token = authService.getToken();

	console.log('[AuthInterceptor] URL:', req.url);
	console.log('[AuthInterceptor] Token:', token ? `${token.substring(0, 20)}...` : 'NO TOKEN');

	if (token) {
		const cloned = req.clone({
			headers: req.headers.set('Authorization', `Bearer ${token}`)
		});
		console.log('[AuthInterceptor] Authorization header agregado');
		return next(cloned);
	}

	console.log('[AuthInterceptor] Sin token, request sin modificar');
	return next(req);
};
