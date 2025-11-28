import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';

interface ContingenciaRow {
	anio: number;
	nro_factura: number;
	fecha_emision?: string;
	monto_total?: string | number;
	cliente?: string;
	codigo_cufd?: string;
	cafc?: string;
	codigo_punto_venta?: string | number;
	codigo_evento?: number | null;
	descripcion_evento?: string | null;
	estado?: string;
	horas_restantes?: number;
	fuera_de_plazo?: boolean;
	es_manual?: boolean;
}

@Component({
	selector: 'app-contingencias',
	standalone: true,
	imports: [CommonModule, FormsModule],
	templateUrl: './contingencias.component.html',
	styleUrls: ['./contingencias.component.scss']
})
export class ContingenciasComponent implements OnInit {
	loading = false;
	loadingStats = false;
	regularizando = false;
	error = '';
	mensaje = '';

	facturas: ContingenciaRow[] = [];
	stats: { total?: number; fuera_de_plazo?: number; por_vencer?: number; vigentes?: number } = {} as any;

	selected: { [key: string]: boolean } = {};
	selectAll = false;

	sucursal: string | number | null = null;
	puntoVenta: string | number | null = null;

	constructor(private cobros: CobrosService) {}

	ngOnInit(): void {
		this.load();
	}

	keyOf(f: ContingenciaRow): string { return `${f.nro_factura}-${f.anio}`; }

	refresh(): void { this.load(); }

	private load(): void {
		this.error = '';
		this.mensaje = '';
		this.loading = true;
		this.cobros.getContingencias({ sucursal: this.sucursal as any, punto_venta: this.puntoVenta as any })
			.subscribe({
				next: (res: any) => {
					this.facturas = (res?.data || []) as ContingenciaRow[];
					this.selected = {};
					this.selectAll = false;
				},
				error: (err) => { this.error = err?.error?.message || 'Error cargando contingencias'; },
				complete: () => { this.loading = false; }
			});

		this.loadingStats = true;
		this.cobros.getContingenciasEstadisticas().subscribe({
			next: (r) => { this.stats = r?.data || {}; },
			error: () => { /* opcional */ },
			complete: () => { this.loadingStats = false; }
		});
	}

	toggleAll(): void {
		this.selectAll = !this.selectAll;
		(this.facturas || []).forEach(f => { this.selected[this.keyOf(f)] = this.selectAll; });
	}

	isAnySelected(): boolean { return Object.values(this.selected).some(v => !!v); }
	selectedCount(): number { return Object.values(this.selected).filter(v => !!v).length; }

	isManual(f: ContingenciaRow): boolean { return !!(f.es_manual || false); }

	regularizarSeleccionadas(): void {
		if (!this.isAnySelected()) return;
		const list = (this.facturas || [])
			.filter(f => !!this.selected[this.keyOf(f)])
			.map(f => ({ nro_factura: f.nro_factura, anio: f.anio }));
		if (list.length === 0) return;

		this.regularizando = true;
		this.error = '';
		this.mensaje = '';
		this.cobros.regularizarContingencias(list).subscribe({
			next: (res: any) => {
				const ok = res?.success === true && (res?.data?.success === true || res?.paquetes_exitosos > 0);
				this.mensaje = ok ? 'Regularización enviada. Revise los estados en unos momentos.' : (res?.data?.error || res?.error || 'Se envió la solicitud con observaciones.');
				this.load();
			},
			error: (err) => {
				this.error = err?.error?.message || 'Error al regularizar contingencias';
			},
			complete: () => { this.regularizando = false; }
		});
	}

	truncate(val: any, len: number = 18): string {
		const s = String(val || '');
		return s.length > len ? s.substring(0, len) + '…' : s;
	}
}
