import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize } from 'rxjs/operators';
import { LoadingService } from '../services/loading.service';

/**
 * Peticiones rápidas de catálogos / combos / validaciones del formulario «Otros ingresos» (y pantalla modificación):
 * no bloquean la UI con el overlay global (misma idea que QR y pensums).
 * Se excluyen guardados, borrados y descarga de PDF.
 */
function skipLoadingOtrosIngresosFormUrls(url: string): boolean {
	if (url.includes('/cuentas-bancarias')) {
		return true;
	}
	/** GET listado de carreras (combo Carrera al cargar el formulario). */
	if (/\/carreras(\?|$)/.test(url)) {
		return true;
	}
	/** GET pensums al cambiar carrera. */
	if (url.includes('/carreras/') && url.includes('/pensums')) {
		return true;
	}
	if (url.includes('/economico/otros-ingresos/')) {
		if (url.includes('/registrar') || url.includes('/nota-pdf')) {
			return false;
		}
		return true;
	}
	if (url.includes('/economico/mod-otros-ingresos/')) {
		if (url.includes('/registrar-mod') || url.includes('/eliminar')) {
			return false;
		}
		return true;
	}
	/** Búsqueda / alta rápida de NIT + razón social en catálogo (`/razon-social`, `/razon-social/search`). */
	if (url.includes('/razon-social')) {
		return true;
	}
	return false;
}

export const loadingInterceptor: HttpInterceptorFn = (req, next) => {
	const loader = inject(LoadingService);
	const url = req.url || '';
	const skipHeader = req.headers.has('X-Skip-Loading');
	const skipByUrl =
		url.includes('/api/qr/status') ||
		url.includes('/api/qr/sync-by-codceta') ||
		url.includes('/api/qr/state-by-codceta') ||
		skipLoadingOtrosIngresosFormUrls(url);
	if (skipHeader || skipByUrl) {
		const cleanReq = skipHeader ? req.clone({ headers: req.headers.delete('X-Skip-Loading') }) : req;
		return next(cleanReq);
	}
	loader.show();
	return next(req).pipe(
		finalize(() => loader.hide())
	);
};
