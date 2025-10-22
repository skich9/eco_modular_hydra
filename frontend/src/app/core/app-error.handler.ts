import { ErrorHandler } from '@angular/core';

export class AppErrorHandler implements ErrorHandler {
	private seen = new Set<string>();
	private ttlMs = 8000;

	handleError(error: any): void {
		try {
			const key = this.serialize(error);
			if (this.isIgnorable(error)) return;
			if (key) {
				if (this.seen.has(key)) return;
				this.seen.add(key);
				setTimeout(() => this.seen.delete(key), this.ttlMs);
			}
			console.error(error);
		} catch {
			console.error(error);
		}
	}

	private isIgnorable(err: any): boolean {
		try {
			const code = typeof err?.code === 'number' ? err.code : undefined;
			if (code === 1050 || code === 1350) return true;
			const msg = (typeof err === 'string' ? err : (err?.message || '')) + '';
			if (msg.includes('"code":1050') || msg.includes('"code":1350')) return true;
			return false;
		} catch { return false; }
	}

	private serialize(err: any): string {
		try {
			if (!err) return '';
			if (typeof err === 'string') return err;
			const obj: any = {
				name: err?.name || 'Error',
				message: err?.message || '',
				code: err?.code || '',
				status: err?.status || '',
				url: err?.url || '',
			};
			return JSON.stringify(obj);
		} catch { return ''; }
	}
}
