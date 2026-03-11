import { Component, OnInit, ChangeDetectorRef  } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';
import { PuntoVentaService, PuntoVenta } from '../../../../services/punto-venta.service';

interface ContingenciaRow {
	anio: number;
	nro_factura: number;
	fecha_emision?: string;
	monto_total?: string | number;
	cliente?: string;
	codigo_cufd?: string;
	cafc?: string;
  codigo_sucursal?: string | number;
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
	loadingPuntosVenta = false;
	regularizando = false;
	error = '';
	mensaje = '';

	facturas: ContingenciaRow[] = [];
	stats: { total?: number; fuera_de_plazo?: number; por_vencer?: number; vigentes?: number } = {} as any;

	selected: { [key: string]: boolean } = {};
	selectAll = false;

	sucursal: number | null = null;
	puntoVenta: number | null = null;
	filtroEstado: string = '';

	puntosVenta: PuntoVenta[] = [];

	get sucursales(): number[] {
		const unique = new Set(this.puntosVenta.map(pv => pv.sucursal));
		return Array.from(unique).sort((a, b) => a - b);
	}

	get puntosFiltrados(): PuntoVenta[] {
		if (this.sucursal === null) return this.puntosVenta;
		return this.puntosVenta.filter(pv => pv.sucursal === this.sucursal);
	}

  // changeDetectorRef:ChangeDetectorRef;

	constructor(private cobros: CobrosService, private pvService: PuntoVentaService) {
    // this.changeDetectorRef = changeDetectorRef;
  }

	ngOnInit(): void {
		this.loadPuntosVenta();
		this.load();
	}

	private loadPuntosVenta(): void {
		this.loadingPuntosVenta = true;
		this.pvService.getPuntosVenta().subscribe({
			next: (res) => {
        console.log('Puntos de Venta obtenidos   xxxxxx:', res?.data);
        this.puntosVenta = res?.data || [];
      },
			error: () => {
        /* no bloquear la pantalla si falla */
        console.log('Error al cargar puntos de venta, se intentará nuevamente en la próxima carga.');
      },
			complete: () => { this.loadingPuntosVenta = false; }
		});
	}

	onSucursalChange(): void {
		this.puntoVenta = null;
	}

	get facturasFiltradas(): ContingenciaRow[] {
		if (!this.filtroEstado) return this.facturas;
    // devolver todas las factura cuyo tiempo o plazo para regularizar ya se haya vencido, es decir, aquellas que tengan fuera_de_plazo = true
		if (this.filtroEstado === 'FUERA_DE_PLAZO') return this.facturas.filter(f => f.fuera_de_plazo);
		return this.facturas.filter(f => (f.estado || '').toUpperCase() === this.filtroEstado);
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
    let condator = 0;
    const arrReferencia = this.facturas || [];
		arrReferencia.forEach(f => {
      if(!f.fuera_de_plazo) {
        this.selected[this.keyOf(f)] = this.selectAll;
        condator++;
      }
    });
    if(condator !== arrReferencia.length) {
      this.mensaje = "No se seleccionan las contingencias que están fuera de plazo, " + condator + " de " + arrReferencia.length + " seleccionadas.";
    }
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

  onModelChange(event: any): void {
    console.log('se cambio el estado del input:', event);
  }
}
