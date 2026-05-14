import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../../../environments/environment';

export interface NotaReposicionOIRow {
	documento: string;
	correlativo: number;
	usuario: string;
	fecha_registro: string;
	cod_ceta: number | null;
	nombre: string;
	monto: number;
	concepto: string;
	observaciones: string;
	nro_recibo: string;
}

@Component({
	selector: 'app-nota-reposicion-otros-ingresos',
	standalone: true,
	imports: [CommonModule, FormsModule, RouterModule],
	templateUrl: './nota-reposicion-otros-ingresos.component.html',
	styleUrls: ['./nota-reposicion-otros-ingresos.component.scss'],
})
export class NotaReposicionOtrosIngresosComponent {
	tab: 'numero' | 'fecha' = 'numero';
	numeroDoc = '';
	/** `yyyy-mm-dd` para `<input type="date">`; la API sigue recibiendo `dd/mm/aaaa`. */
	fechaIniIso = '';
	fechaFinIso = '';
	rows: NotaReposicionOIRow[] = [];
	loading = false;
	alertMsg = '';
	alertType: 'warning' | 'danger' | '' = '';

	private readonly base = `${environment.apiUrl}/economico/otros-ingresos/reimpresion-reposicion-otros-ingresos`;

	constructor(private http: HttpClient) {
		const h = NotaReposicionOtrosIngresosComponent.hoyIso();
		this.fechaIniIso = h;
		this.fechaFinIso = h;
	}

	private static hoyIso(): string {
		const d = new Date();
		const p = (n: number) => n.toString().padStart(2, '0');

		return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
	}

	/** dd/mm/aaaa para el backend (igual validación Laravel `d/m/Y`). */
	private isoADmy(iso: string): string {
		const t = iso.trim();
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(t);
		if (!m) {
			return '';
		}

		return `${m[3]}/${m[2]}/${m[1]}`;
	}

	clampRangoFechas(): void {
		if (
			this.fechaIniIso &&
			this.fechaFinIso &&
			/^\d{4}-\d{2}-\d{2}$/.test(this.fechaIniIso) &&
			/^\d{4}-\d{2}-\d{2}$/.test(this.fechaFinIso)
		) {
			if (this.fechaIniIso > this.fechaFinIso) {
				this.fechaFinIso = this.fechaIniIso;
			}
		}
	}

	setTab(t: 'numero' | 'fecha'): void {
		this.tab = t;
		this.rows = [];
		this.clearAlert();
	}

	buscarPorFecha(): void {
		this.clearAlert();
		this.clampRangoFechas();
		const a = this.isoADmy(this.fechaIniIso);
		const b = this.isoADmy(this.fechaFinIso);
		if (!a || !b) {
			this.flash('faltan datos', 'warning');

			return;
		}
		this.loading = true;
		this.http
			.post<{ success: boolean; rows?: NotaReposicionOIRow[]; message?: string }>(`${this.base}/buscar-fecha`, {
				opcion: 'fecha_nota',
				fecha_ini: a,
				fecha_fin: b,
			})
			.subscribe({
				next: (res) => {
					this.loading = false;
					if (!res?.success) {
						this.flash(res?.message || 'Error en búsqueda', 'danger');

						return;
					}
					this.rows = res.rows || [];
				},
				error: (e) => {
					this.loading = false;
					const m = e?.error?.message || e?.message || 'Error de red';
					this.flash(String(m), 'danger');
				},
			});
	}

	buscarPorNumero(): void {
		this.clearAlert();
		const v = (this.numeroDoc || '').trim();
		if (!v) {
			this.mensajeNumeroNulo();

			return;
		}
		if (v === '0') {
			this.mensajeNumeroCero(v);

			return;
		}
		if (v.length !== 8) {
			this.mensajeTamano(v);

			return;
		}
		if (!/^[A-Za-z0-9]{8}$/.test(v)) {
			this.mensajeTamano(v);

			return;
		}
		this.loading = true;
		this.http
			.post<{ success: boolean; rows?: NotaReposicionOIRow[] }>(`${this.base}/buscar-doc`, {
				nro_nota_deposito: v,
			})
			.subscribe({
				next: (res) => {
					this.loading = false;
					if (!res?.success) {
						this.flash('Error en búsqueda', 'danger');

						return;
					}
					this.rows = res.rows || [];
				},
				error: () => {
					this.loading = false;
					this.flash('Error de red', 'danger');
				},
			});
	}

	onNumeroKeydown(ev: KeyboardEvent): void {
		if (ev.key === 'Enter') {
			ev.preventDefault();
			this.buscarPorNumero();
		}
	}

	descargar(doc: string): void {
		this.clearAlert();
		this.loading = true;
		this.http.post<{ success: boolean; url?: string; message?: string }>(`${this.base}/generar-nota`, { num_doc: doc }).subscribe({
			next: (res) => {
				this.loading = false;
				if (!res?.success || !res.url) {
					this.flash(res?.message || 'No se pudo generar el PDF', 'danger');

					return;
				}
				window.open(res.url, '_blank', 'noopener');
			},
			error: (e) => {
				this.loading = false;
				const m = e?.error?.message || e?.message || 'Error al generar PDF';
				this.flash(String(m), 'danger');
			},
		});
	}

	private mensajeNumeroNulo(): void {
		this.flash(
			'Introduzca un número para buscar. Por favor verifique los datos.',
			'warning'
		);
	}

	private mensajeNumeroCero(v: string): void {
		this.flash(
			`El número introducido (${v}) no es válido. Introduzca un número mayor a cero para proseguir.`,
			'warning'
		);
	}

	private mensajeTamano(v: string): void {
		this.flash(
			`El número ingresado (${v}) debe contener el siguiente formato EAA000NN, donde E es el prefijo de carrera, AA es el año y NN el correlativo y el número debe contener 8 caracteres en total.`,
			'warning'
		);
	}

	private flash(t: string, ty: 'warning' | 'danger'): void {
		this.alertMsg = t;
		this.alertType = ty;
	}

	private clearAlert(): void {
		this.alertMsg = '';
		this.alertType = '';
	}
}
