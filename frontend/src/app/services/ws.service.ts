import { Injectable, Inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { Subject } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class WsService {
	private ws: WebSocket | null = null;
	private msgs = new Subject<any>();
	public messages$ = this.msgs.asObservable();
	private currentAlias: string | null = null;
	private connecting = false;
    private reconnectTimer: any = null;
    private reconnectAttempts = 0;
    private readonly reconnectMax = 5;

	constructor(@Inject(PLATFORM_ID) private platformId: Object) {}

	private buildUrl(): string {
		if (!isPlatformBrowser(this.platformId)) return '';
		const loc = (typeof window !== 'undefined') ? window.location : ({} as any);
		const isHttps = loc.protocol === 'https:';
		const scheme = isHttps ? 'wss' : 'ws';
		const isDev4200 = loc.port === '4200';
		const isTunnel = (loc.hostname || '').includes('devtunnels') || (loc.hostname || '').includes('ngrok');

        // Regla: si estamos en 4200 => usar :8069
        if (isDev4200) {
            return `${scheme}://${loc.hostname}:8069/ws`;
        }
        // Si es túnel https, mapear -<puerto> a -8069 y no usar puerto explícito
        if (isTunnel) {
            const hn = String(loc.hostname || '');
            const mapped = hn.replace(/-(\d+)(?=\.|$)/, '-8069');
            return `${scheme}://${mapped}/ws`;
        }
        // Caso por defecto (puertos de previsualización como 127.0.0.1:55754):
        // Usar host actual con el puerto del backend configurado en environment
        try {
            const api = new URL(environment.apiUrl, `${loc.protocol}//${loc.host}`);
            const apiPort = (environment as any)?.apiPort || api.port;
            const hostPort = apiPort ? `${loc.hostname}:${apiPort}` : api.host;
            return `${scheme}://${hostPort}/ws`;
        } catch (e) {
            const apiPort = (environment as any)?.apiPort || '8069';
            return `${scheme}://${loc.hostname}:${apiPort}/ws`;
        }
	}

	public connect(alias: string): void {
		if (!isPlatformBrowser(this.platformId)) return;
		if (!alias) return;
		if (this.ws && this.ws.readyState === WebSocket.OPEN && this.currentAlias === alias) return;
		if (this.connecting) return;
		this.disconnect();
		const url = this.buildUrl();
		try {
			try { console.log('[WS] connecting', { url }); } catch {}
			this.connecting = true;
			const ws = new WebSocket(url);
			this.ws = ws;
			this.currentAlias = alias;
			ws.onopen = () => {
				try { console.log('[WS] open', { url, alias }); } catch {}
				this.connecting = false;
				try { ws.send(JSON.stringify({ evento: 'escuchar_factura', id_pago: alias })); } catch {}
				// Enviar ping de prueba para verificar ruta
				try { ws.send(JSON.stringify({ evento: 'prueba_socket' })); } catch {}
				this.reconnectAttempts = 0;
			};
			ws.onmessage = (ev) => {
				try {
					const data = JSON.parse(ev.data || '{}');
					this.msgs.next(data);
				} catch {}
			};
			ws.onclose = (ev) => {
				try { console.log('[WS] close', { code: (ev as any)?.code, reason: (ev as any)?.reason }); } catch {}
				this.connecting = false;
				this.tryScheduleReconnect();
			};
			ws.onerror = (err) => { try { console.log('[WS] error', err); } catch {}; this.tryScheduleReconnect(); };
		} catch {}
	}

	public disconnect(): void {
		try {
			if (this.ws) {
				try { this.ws.close(); } catch {}
			}
		} finally {
			this.ws = null;
			this.currentAlias = null;
			this.connecting = false;
            if (this.reconnectTimer) { try { clearTimeout(this.reconnectTimer); } catch {}; this.reconnectTimer = null; }
		}
	}

	private tryScheduleReconnect(): void {
		if (!isPlatformBrowser(this.platformId)) return;
		if (!this.currentAlias) return;
		if (this.connecting) return;
		if (this.reconnectAttempts >= this.reconnectMax) return;
		if (this.reconnectTimer) return;
		const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 10000);
		this.reconnectTimer = setTimeout(() => {
			this.reconnectTimer = null;
			this.reconnectAttempts++;
			try { console.log('[WS] reconnect attempt', { attempt: this.reconnectAttempts }); } catch {}
			if (this.currentAlias) this.connect(this.currentAlias);
		}, delay);
	}
}
