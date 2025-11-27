import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { FormsModule } from '@angular/forms';
import { CobrosService } from '../../../../services/cobros.service';
import { generateQuickFacturaPdf } from '../../../../utils/pdf.helpers';

@Component({
	selector: 'app-estado-factura',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './estado-factura.component.html',
	styleUrls: ['./estado-factura.component.scss']
})
export class EstadoFacturaComponent implements OnInit {
	form: FormGroup;
	loading = false;
	errorMsg = '';

	// Tabla paginada
	rows: Array<any> = [];
	meta: { page: number; per_page: number; total: number; last_page: number } = { page: 1, per_page: 10, total: 0, last_page: 0 };
	pageOptions = [10, 20, 50];
	filterAnio: number | null = new Date().getFullYear();
	loadingList = false;

	// Detalles
	detailRow: any = null;

	// Modal de estado (respuesta de impuestos)
	statusRow: any = null;
	statusLoading = false;
	statusData: any = null;
	statusError = '';
	// Anulación
	anulando = false;
	codigoMotivo = 1;
	motivos: Array<{ codigo: number|string; descripcion: string }> = [];

	constructor(private fb: FormBuilder, private cobros: CobrosService) {
		const year = new Date().getFullYear();
		this.form = this.fb.group({
			anio: [year, [Validators.required, Validators.min(2000), Validators.max(2100)]],
			nro: [null, [Validators.required, Validators.min(1)]]
		});
	}

	ngOnInit(): void {
		this.loadList();
	}

	get badgeClass(): string { return this.stateBadgeClass(this.statusData?.estado || ''); }

	// Normalización de campos devueltos por backend/SIN
	get statusCodigoEstado(): string {
		const d = this.statusData || {};
		const raw = d.raw || {};
		const rsf = raw.RespuestaServicioFacturacion || raw.respuestaServicioFacturacion || {};
		let val = (d.codigo_estado != null ? d.codigo_estado : null)
			?? (d.codigoEstado != null ? d.codigoEstado : null)
			?? (raw && raw.codigoEstado != null ? raw.codigoEstado : null)
			?? (rsf && rsf.codigoEstado != null ? rsf.codigoEstado : null);
		if (val == null && raw && typeof raw === 'object') {
			for (const v of Object.values(raw)) {
				if (v && typeof v === 'object' && 'codigoEstado' in (v as any)) { val = (v as any).codigoEstado; break; }
			}
		}
		return (val ?? '').toString();
	}

	get statusDescripcion(): string {
		const d = this.statusData || {};
		const raw = d.raw || {};
		const rsf = raw.RespuestaServicioFacturacion || raw.respuestaServicioFacturacion || {};
		const mensajes = raw.mensajesList || {};
		const estadoStr = (d.estado || '').toString().toUpperCase();
		const codEst = (d.codigo_estado ?? d.codigoEstado ?? rsf?.codigoEstado ?? raw?.codigoEstado) as any;
		// Regla: si es ACEPTADA/VIGENTE (o código 690) usar codigoDescripcion (p.ej. "VALIDA")
		let val = (estadoStr.includes('ACEPT') || estadoStr.includes('VIGENTE') || Number(codEst) === 690)
			? (rsf?.codigoDescripcion ?? (raw as any)?.codigoDescripcion ?? null)
			: null;
		// Fallbacks para casos RECHAZADA/ERROR u otros
		if (val == null) {
			val = (d.codigo_descripcion ? d.codigo_descripcion : null)
				?? (d.descripcion ? d.descripcion : null)
				?? (mensajes && mensajes.descripcion ? mensajes.descripcion : null)
				?? (rsf && rsf.descripcion ? rsf.descripcion : null);
		}
		if (val == null && raw && typeof raw === 'object') {
			for (const v of Object.values(raw)) {
				if (v && typeof v === 'object' && 'descripcion' in (v as any)) { val = (v as any).descripcion; break; }
			}
		}
		return (val ?? '').toString();
	}

	get statusCodigoRecepcion(): string {
		const d = this.statusData || {};
		const raw = d.raw || {};
		const rsf = raw.RespuestaServicioFacturacion || raw.respuestaServicioFacturacion || {};
		let val = (d.codigo_recepcion ? d.codigo_recepcion : null)
			?? (d.codigoRecepcion ? d.codigoRecepcion : null)
			?? (raw && raw.codigoRecepcion ? raw.codigoRecepcion : null)
			?? (rsf && rsf.codigoRecepcion ? rsf.codigoRecepcion : null);
		if (val == null && raw && typeof raw === 'object') {
			for (const v of Object.values(raw)) {
				if (v && typeof v === 'object' && 'codigoRecepcion' in (v as any)) { val = (v as any).codigoRecepcion; break; }
			}
		}
		return (val ?? '').toString();
	}

	stateBadgeClass(estStr: string): string {
		const est = (estStr || '').toString().toUpperCase();
		if (!est) return 'badge bg-secondary';
		if (est.includes('ENVIADO') || est.includes('ACEPT') || est.includes('VIGENTE')) return 'badge bg-success';
		if (est.includes('ANUL') || est.includes('RECH') || est.includes('ERROR')) return 'badge bg-danger';
		if (est.includes('PROCES')) return 'badge bg-warning text-dark';
		return 'badge bg-secondary';
	}

	horasClass(horas: number): string {
		const n = Number(horas || 0);
		if (n <= 6) return 'text-danger fw-semibold';
		if (n <= 24) return 'text-warning fw-semibold';
		return 'text-success fw-semibold';
	}

	esAnulada(estado: string): boolean {
		const est = (estado || '').toString().toUpperCase();
		return est.includes('ANUL');
	}

	esRechazada(estado: string): boolean {
		const est = (estado || '').toString().toUpperCase();
		return est.includes('RECH') || est.includes('ERROR');
	}

	esEnProceso(estado: string): boolean {
		const est = (estado || '').toString().toUpperCase();
		return est.includes('PROCES');
	}

	puedeAnular(estado: string): boolean {
		// Solo se puede anular si está ACEPTADA/ENVIADA/VIGENTE
		// NO se puede anular si está: ANULADA, RECHAZADA, EN_PROCESO
		if (!estado) return false;
		if (this.esAnulada(estado)) return false;
		if (this.esRechazada(estado)) return false;
		if (this.esEnProceso(estado)) return false;
		return true;
	}

	mensajeBloqueoAnulacion(estado: string): string {
		if (this.esAnulada(estado)) return 'Esta factura ya se encuentra anulada.';
		if (this.esRechazada(estado)) return 'No se puede anular una factura rechazada.';
		if (this.esEnProceso(estado)) return 'La factura está en proceso. Espere a que se complete la operación.';
		return '';
	}

	verificar(): void {
		this.errorMsg = '';
		if (this.form.invalid) return;
		const anio = Number(this.form.get('anio')?.value || 0);
		const nro = Number(this.form.get('nro')?.value || 0);
		this.loading = true;
		this.openEstado({ anio, nro_factura: nro }, true);
	}

	anular(row?: any): void { /* noop: usamos el botón del modal */ }

	// Tabla paginada
	loadList(): void {
		this.loadingList = true;
		const params: any = { page: this.meta.page, per_page: this.meta.per_page || 10 };
		if (this.filterAnio) params.anio = this.filterAnio;
		this.cobros.getFacturasLista(params).subscribe({
			next: (res: any) => {
				this.rows = Array.isArray(res?.data) ? res.data : [];
				const m = res?.meta || {};
				this.meta = {
					page: Number(m.page || params.page || 1),
					per_page: Number(m.per_page || params.per_page || 10),
					total: Number(m.total || this.rows.length || 0),
					last_page: Number(m.last_page || 1)
				};
				this.loadingList = false;
			},
			error: () => { this.rows = []; this.loadingList = false; }
		});
	}

	setPage(p: number): void { if (p < 1 || p > (this.meta.last_page||1)) return; this.meta.page = p; this.loadList(); }
	setPerPage(n: number): void { this.meta.per_page = n; this.meta.page = 1; this.loadList(); }
	buscarPorAnio(): void { this.meta.page = 1; this.loadList(); }

	refreshEstado(row: any): void { this.openEstado(row); }

	openDetalles(row: any): void {
		this.detailRow = row;
		try {
			const el = document.getElementById('detalleFacturaModal');
			const bs = (window as any).bootstrap;
			if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el)).show(); return; }
		} catch {}
	}

	openEstado(row: any, fromTopForm: boolean = false): void {
		const anio = Number(row?.anio || 0);
		const nro = Number(row?.nro_factura || 0);
		if (!anio || !nro) { this.loading = false; return; }
		if (!fromTopForm) { row.__updating = true; }
		this.statusRow = row;
		this.statusLoading = true;
		this.statusData = null;
		this.statusError = '';
		// cargar motivos de anulación (una sola vez por sesión de componente)
		if (!this.motivos || this.motivos.length === 0) {
			this.cobros.getMotivosAnulacion().subscribe({
				next: (list) => {
					this.motivos = list || [];
					// si existe motivo con código 1, seleccionar por defecto; sino el primero
					const def = this.motivos.find(m => String(m.codigo) === '1');
					this.codigoMotivo = def ? Number(def.codigo) : (this.motivos[0] ? Number(this.motivos[0].codigo) : 1);
				}
			});
		}
		this.cobros.getFacturaEstado(anio, nro).subscribe({
			next: (res: any) => {
				this.statusData = res?.data || {};
				if (!fromTopForm) { row.estado = this.statusData?.estado || row.estado; row.__updating = false; }
				this.statusLoading = false;
				this.loading = false;
				try {
					const el = document.getElementById('estadoFacturaModal');
					const bs = (window as any).bootstrap;
					if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el)).show(); }
				} catch {}
			},
			error: (err: any) => {
				this.statusError = err?.error?.message || 'Error consultando estado en SIN';
				if (!fromTopForm && row) row.__updating = false;
				this.statusLoading = false;
				this.loading = false;
				try {
					const el = document.getElementById('estadoFacturaModal');
					const bs = (window as any).bootstrap;
					if (el && bs?.Modal) { (bs.Modal.getInstance(el) || new bs.Modal(el)).show(); }
				} catch {}
			}
		});
	}

	anularDesdeModal(): void {
		this.statusError = '';
		if (!this.statusRow) return;
		const anio = Number(this.statusRow?.anio || 0);
		const nro = Number(this.statusRow?.nro_factura || 0);
		if (!anio || !nro) { this.statusError = 'Factura inválida'; return; }
		this.anulando = true;
		this.cobros.anularFactura(anio, nro, Number(this.codigoMotivo || 1)).subscribe({
			next: (res: any) => {
				const postEstado = (res?.post_estado || '').toString().toUpperCase();
				if (postEstado === 'ANULADA') {
					// Anulación exitosa inmediata
					this.finalizarAnulacionExitosa(anio, nro);
				} else {
					// Polling de estado si queda EN_PROCESO
					this.pollEstadoPostAnulacion(anio, nro, 6, 1500);
				}
			},
			error: (err: any) => {
				this.statusError = err?.error?.message || 'No se pudo anular la factura';
				this.anulando = false;
			}
		});
	}

	private pollEstadoPostAnulacion(anio: number, nro: number, tries: number, delayMs: number): void {
		if (tries <= 0) {
			// último refresh y salir
			this.cobros.getFacturaEstado(anio, nro).subscribe({
				next: (st: any) => {
					this.statusData = st?.data || {};
					const est = (this.statusData?.estado || '').toString().toUpperCase();
					if (this.statusRow) this.statusRow.estado = this.statusData?.estado || this.statusRow.estado;
					if (est === 'ANULADA') {
						this.finalizarAnulacionExitosa(anio, nro);
					} else {
						this.anulando = false;
					}
				},
				error: () => { this.anulando = false; }
			});
			return;
		}
		this.cobros.getFacturaEstado(anio, nro).subscribe({
			next: (st: any) => {
				this.statusData = st?.data || {};
				const est = (this.statusData?.estado || '').toString().toUpperCase();
				if (this.statusRow) this.statusRow.estado = this.statusData?.estado || this.statusRow.estado;
				if (est === 'EN_PROCESO') {
					setTimeout(() => this.pollEstadoPostAnulacion(anio, nro, tries - 1, delayMs), delayMs);
				} else if (est === 'ANULADA') {
					this.finalizarAnulacionExitosa(anio, nro);
				} else {
					this.anulando = false;
				}
			},
			error: () => {
				setTimeout(() => this.pollEstadoPostAnulacion(anio, nro, tries - 1, delayMs), delayMs);
			}
		});
	}

	private finalizarAnulacionExitosa(anio: number, nro: number): void {
		this.anulando = false;
		// Actualizar estado en la fila de la tabla
		if (this.statusRow) {
			this.statusRow.estado = 'ANULADA';
		}
		// Cerrar el modal
		try {
			const el = document.getElementById('estadoFacturaModal');
			const bs = (window as any).bootstrap;
			if (el && bs?.Modal) {
				const modal = bs.Modal.getInstance(el);
				if (modal) modal.hide();
			}
		} catch {}
		// Recargar la lista para actualizar los botones
		this.loadList();
		// Mostrar mensaje de éxito
		alert('Factura anulada correctamente. El PDF con marca de anulación se descargará automáticamente.');
		// Descargar PDF anulado (después del mensaje para mejor UX)
		setTimeout(() => {
			this.descargarPdfAnulado(anio, nro);
		}, 500);
	}

	private descargarPdfAnulado(anio: number, nro: number): void {
		// Obtener datos de la factura del backend
		// Usar URL absoluta al backend de Laravel (puerto 8069)
		const url = `http://localhost:8069/api/facturas/${anio}/${nro}/datos`;
		
		console.log('Solicitando datos de factura:', url);
		
		fetch(url, {
			method: 'GET',
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json'
			}
		})
		.then(response => {
			console.log('Response status:', response.status);
			console.log('Response headers:', response.headers);
			
			if (!response.ok) {
				return response.text().then(text => {
					console.error('Response error body:', text);
					throw new Error(`Error ${response.status}: ${text.substring(0, 100)}`);
				});
			}
			
			const contentType = response.headers.get('content-type');
			if (!contentType || !contentType.includes('application/json')) {
				return response.text().then(text => {
					console.error('Response is not JSON:', text.substring(0, 200));
					throw new Error('El servidor no devolvió JSON. Posiblemente la ruta no existe o Laravel devolvió un error HTML.');
				});
			}
			
			return response.json();
		})
		.then(data => {
			console.log('Datos recibidos:', data);
			// Generar PDF con jsPDF en el frontend
			const factura = data.factura || {};
			const detalles = data.detalles || [];
			
			// Preparar items para el PDF
			const items = detalles.map((d: any) => ({
				codigoProducto: d.codigo_producto || '',
				descripcion: d.descripcion || 'Item',
				nombreUnidadMedida: d.unidad_medida || 'UNI',
				cantidad: Number(d.cantidad || 1),
				precioUnitario: Number(d.precio_unitario || 0),
				montoDescuento: Number(d.descuento || 0),
				subTotal: Number(d.subtotal || 0)
			}));
			
			// Generar PDF con marca de ANULADO
			generateQuickFacturaPdf({
				anio: anio,
				nro: nro,
				numeroFactura: nro,
				razon: factura.cliente || factura.razon || 'S/N',
				nit: factura.nit || '0',
				codigoCliente: factura.codigo_cliente || '',
				fechaEmision: factura.fecha_emision || new Date().toISOString(),
				periodo: factura.periodo_facturado || '',
				nombreEstudiante: factura.nombre_estudiante || '',
				detalle: items.length > 0 ? items[0].descripcion : 'Factura anulada',
				cantidad: items.length > 0 ? items[0].cantidad : 0,
				pu: items.length > 0 ? items[0].precioUnitario : 0,
				descuento: factura.descuento_adicional || 0,
				montoGift: factura.monto_gift_card || 0,
				total: Number(factura.monto_total || 0),
				importeBase: Number(factura.monto_total_sujeto_iva || 0),
				cuf: factura.cuf || '',
				sucursal: factura.codigo_sucursal || '0',
				puntoVenta: factura.codigo_punto_venta || '0',
				items: items,
				leyenda: '*** FACTURA ANULADA ***',
				leyenda2: 'Este documento ha sido anulado y no tiene validez fiscal',
				formato: 'a4'
			});
		})
		.catch(error => {
			console.error('Error generando PDF:', error);
			alert('Error al generar el PDF. Por favor, intente nuevamente.');
		});
	}
}
