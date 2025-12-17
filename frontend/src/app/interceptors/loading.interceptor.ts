import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { finalize } from 'rxjs/operators';
import { LoadingService } from '../services/loading.service';

export const loadingInterceptor: HttpInterceptorFn = (req, next) => {
	const loader = inject(LoadingService);
	const url = req.url || '';
	const skipHeader = req.headers.has('X-Skip-Loading');
	const skipByUrl = url.includes('/api/qr/status') || url.includes('/api/qr/sync-by-codceta') || url.includes('/api/qr/state-by-codceta');
	if (skipHeader || skipByUrl) {
		const cleanReq = skipHeader ? req.clone({ headers: req.headers.delete('X-Skip-Loading') }) : req;
		return next(cleanReq);
	}
	loader.show();
	return next(req).pipe(
		finalize(() => loader.hide())
	);
};
