import { Directive, ElementRef, HostListener, Input, OnDestroy } from '@angular/core';
import { Observable, Subscription } from 'rxjs';

@Directive({
	selector: '[appClickLock]',
	standalone: true
})
export class ClickLockDirective implements OnDestroy {
	@Input('appClickLock') enabled: boolean = true;
	@Input() lockDuration?: number;
	@Input() unlock$?: Observable<any>;
	private locked = false;
	private sub?: Subscription;
	private timerId: any;
	private disableDelayId: any;

	constructor(private el: ElementRef<HTMLElement>) {}

	@HostListener('click', ['$event'])
	onClick(ev: Event): void {
		if (!this.enabled) return;
		if (this.locked) {
			ev.stopImmediatePropagation();
			ev.preventDefault();
			return;
		}
		this.lock();
		if (this.unlock$) {
			if (this.sub) { this.sub.unsubscribe(); }
			this.sub = this.unlock$.subscribe({
				next: () => this.unlock(),
				error: () => this.unlock(),
				complete: () => this.unlock()
			});
		} else if (typeof this.lockDuration === 'number' && this.lockDuration > 0) {
			this.timerId = setTimeout(() => this.unlock(), this.lockDuration);
		}
	}

	private lock(): void {
		this.locked = true;
		const native: any = this.el.nativeElement as any;
		const isSubmit = this.isSubmitControl(native);
		if (isSubmit) {
			// Para botones submit: realizar cambios visuales y disabled en el siguiente tick
			// para no interferir con el (ngSubmit) del formulario.
			this.disableDelayId = setTimeout(() => {
				try {
					if ('disabled' in native) native.disabled = true;
					native.setAttribute('aria-disabled', 'true');
					native.style.pointerEvents = 'none';
					native.style.opacity = '0.6';
				} catch {}
			}, 0);
		} else if ('disabled' in native) {
			native.disabled = true;
		} else {
			native.setAttribute('aria-disabled', 'true');
			try { native.style.pointerEvents = 'none'; native.style.opacity = '0.6'; } catch {}
		}
	}

	private isSubmitControl(native: any): boolean {
		try {
			const tag = (native?.tagName || '').toString().toUpperCase();
			const type = (native?.type || '').toString().toLowerCase();
			if (tag === 'BUTTON') {
				// Por especificación, el botón sin type explícito dentro de un form actúa como submit
				return !type || type === 'submit';
			}
			if (tag === 'INPUT') {
				return type === 'submit';
			}
			return false;
		} catch { return false; }
	}

	private unlock(): void {
		this.locked = false;
		const native: any = this.el.nativeElement as any;
		if ('disabled' in native) {
			native.disabled = false;
		} else {
			native.removeAttribute('aria-disabled');
			try { native.style.pointerEvents = ''; native.style.opacity = ''; } catch {}
		}
		if (this.sub) { this.sub.unsubscribe(); this.sub = undefined; }
		if (this.timerId) { clearTimeout(this.timerId); this.timerId = null; }
		if (this.disableDelayId) { clearTimeout(this.disableDelayId); this.disableDelayId = null; }
	}

	ngOnDestroy(): void {
		if (this.sub) { this.sub.unsubscribe(); }
		if (this.timerId) { clearTimeout(this.timerId); }
		if (this.disableDelayId) { clearTimeout(this.disableDelayId); }
	}
}
