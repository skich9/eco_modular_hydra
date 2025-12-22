import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class LoadingService {
	private _pendingCount = 0;
	private _visible = false;
	private _timer: any = null;
	private _state$ = new BehaviorSubject<boolean>(false);

	readonly isLoading$ = this._state$.asObservable();

	show(): void {
		this._pendingCount++;
		if (!this._visible && this._timer == null) {
			// Pequeña demora para evitar parpadeos en requests muy rápidos
			this._timer = setTimeout(() => {
				this._timer = null;
				if (this._pendingCount > 0) {
					this._visible = true;
					this._state$.next(true);
				}
			}, 200);
		}
	}

	hide(): void {
		if (this._pendingCount > 0) { this._pendingCount--; }
		if (this._pendingCount === 0) {
			if (this._timer) { clearTimeout(this._timer); this._timer = null; }
			if (this._visible) {
				this._visible = false;
				this._state$.next(false);
			}
		}
	}

	reset(): void {
		this._pendingCount = 0;
		if (this._timer) { clearTimeout(this._timer); this._timer = null; }
		this._visible = false;
		this._state$.next(false);
	}
}
