import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { CobrosService } from '../../../../services/cobros.service';

@Component({
	selector: 'app-facturacion-posterior',
	standalone: true,
	imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule],
	templateUrl: './facturacion-posterior.component.html',
	styleUrls: ['./facturacion-posterior.component.scss']
})
export class FacturacionPosteriorComponent implements OnInit {
	searchForm: FormGroup;
	observaciones: string = '';
	// Contexto para batchStore
	private ctxCodPensum: string = '';
	private ctxTipoInscripcion: string = '';
	private ctxCodInscrip: number | null = null;
	private ctxGestion: string = '';

	// Control de UI
	locked: boolean = false; // bloquea selección y observaciones tras "Mostrar en tabla"
	student = {
		ap_paterno: '',
		ap_materno: '',
		nombres: '',
		pensum: '',
		gestion: '',
		grupos: [] as string[]
	};
	gestionesCatalogo: string[] = [];
	noInscrito = false;

	// Tabla de cobros previos
	cobros: Array<{
		glosa: string;
		gestion: string;
		fecha: string;
		factura?: number | null;
		recibo?: number | null;
		importe: number;
		usuario: string;
		selected: boolean;
		rawItems: any[];
	}> = [];

	// Detalle modal
	detalleRow: { glosa: string; gestion: string; fecha: string; factura?: number | null; recibo?: number | null; importe: number; usuario: string; selected: boolean; rawItems: any[]; } | null = null;
	detalleItems: any[] = [];

	// Detalle factura (render en tarjeta)
	detalleFacturaVisible = false;
	detalleFactura: Array<{ cantidad: number; detalle: string; pu: number; descuento: number; subtotal: number; m?: string; d?: string; obs?: string; }>=[];
	detalleFacturaTotales: { subtotal: number } = { subtotal: 0 };

	constructor(private fb: FormBuilder, private cobrosService: CobrosService) {
		this.searchForm = this.fb.group({
			cod_ceta: [''],
			gestion: ['']
		});
	}

	ngOnInit(): void {
		// nada por ahora
	}

	buscarPorCodCeta(): void {
		const code = (this.searchForm.value?.cod_ceta || '').toString().trim();
		if (!code) return;
		const gesRaw = (this.searchForm.value?.gestion || '').toString().trim();
		const ges = gesRaw || undefined;
		this.cobrosService.getResumen(code, ges).subscribe({
			next: (res: any) => this.applyResumenData(res),
			error: () => this.resetResumen()
		});
	}

	onGestionChange(): void {
		const code = (this.searchForm.value?.cod_ceta || '').toString().trim();
		if (!code) return;
		this.buscarPorCodCeta();
	}

	private applyResumenData(res: any): void {
		const est = res?.data?.estudiante || {};
		const insc = res?.data?.inscripcion || null;
		const inscripciones = Array.isArray(res?.data?.inscripciones) ? res.data.inscripciones : [];
		const gestionesAll = Array.isArray(res?.data?.gestiones_all) ? (res.data.gestiones_all as string[]) : [];
		const gestiones = (gestionesAll.length ? gestionesAll : Array.from(new Set((inscripciones as any[]).map((i: any) => String(i?.gestion || '')).filter((g: string) => !!g)))) as string[];
		const pensumNombre = (insc?.pensum?.nombre || est?.pensum?.nombre || '') as string;
		this.student = {
			ap_paterno: est?.ap_paterno || '',
			ap_materno: est?.ap_materno || '',
			nombres: est?.nombres || '',
			pensum: pensumNombre,
			gestion: res?.data?.gestion || '',
			grupos: (inscripciones as any[]).filter((i: any) => String(i?.gestion || '') === String(res?.data?.gestion || '')).map((i: any) => String(i?.cod_curso || '')).filter((c: string) => !!c)
		};
		this.gestionesCatalogo = gestiones;
		this.noInscrito = gestiones.length === 0;
		if (this.student.gestion && !this.gestionesCatalogo.includes(this.student.gestion)) {
			this.gestionesCatalogo = [this.student.gestion, ...this.gestionesCatalogo];
		}
		if (!this.searchForm.value?.gestion && this.student.gestion) {
			this.searchForm.patchValue({ gestion: this.student.gestion });
		}

		// Guardar contexto para batchStore
		this.ctxCodPensum = String(insc?.cod_pensum || '');
		this.ctxTipoInscripcion = String(insc?.tipo_inscripcion || '');
		this.ctxCodInscrip = insc?.cod_inscrip ? Number(insc.cod_inscrip) : null;
		this.ctxGestion = String(res?.data?.gestion || '');

		// Reset de UI dependiente
		this.locked = false;
		this.detalleFacturaVisible = false;

		// Mapear cobros previos: usar SOLO mensualidad.items (ya incluye todo)
		const listMens = Array.isArray(res?.data?.cobros?.mensualidad?.items) ? res.data.cobros.mensualidad.items : [];
		
		// Agrupar por nro_recibo
		const byRecibo = new Map<string | number, {
			glosa: string; gestion: string; fecha: string; factura: number | null; recibo: number | null; importe: number; usuario: string; selected: boolean; rawItems: any[];
		}>();
		
		for (const r of (listMens || [])) {
			const fechaRaw = String(r?.fecha_cobro || r?.created_at || '');
			
			// Extraer nickname del usuario correctamente
			let usuario = '';
			if (r?.usuario) {
				if (typeof r.usuario === 'object') {
					usuario = String(r.usuario.nickname || r.usuario.nombre_completo || r.usuario.nombre || '');
				} else {
					usuario = String(r.usuario);
				}
			}
			if (!usuario && r?.id_usuario) {
				usuario = String(r.id_usuario);
			}
			
			const factura = r?.nro_factura != null ? Number(r.nro_factura) : null;
			const recibo = r?.nro_recibo != null ? Number(r.nro_recibo) : null;
			const key = recibo ?? `sin-recibo-${Math.random()}`;
			const existing = byRecibo.get(key);
			
			if (!existing) {
				const conceptoVal = (r?.concepto ?? '').toString().trim();
				const glosa = conceptoVal
					? `Pago de ${conceptoVal} con Recibo: ${recibo ?? ''}`.trim()
					: ((r?.observaciones || '').toString().trim() || this.buildGlosaFallback(r));
				byRecibo.set(key, {
					glosa,
					gestion: String(r?.gestion || ''),
					fecha: fechaRaw,
					factura,
					recibo,
					importe: Number(r?.monto || 0) || 0,
					usuario,
					selected: false,
					rawItems: [r],
				});
			} else {
				// actualizar agregados: fecha más reciente, sumar importe, setear factura si no hay
				const prevTime = new Date(existing.fecha).getTime() || 0;
				const curTime = new Date(fechaRaw).getTime() || 0;
				if (curTime > prevTime) existing.fecha = fechaRaw;
				if (existing.factura == null && factura != null) existing.factura = factura;
				if (!existing.usuario && usuario) existing.usuario = usuario;
				// si aparece un concepto válido y la glosa actual es genérica, actualizarla al nuevo formato solicitado
				const conceptoVal2 = (r?.concepto ?? '').toString().trim();
				if (conceptoVal2 && (!existing.glosa || existing.glosa.startsWith('Pago según') || existing.glosa.startsWith('Pago registrado'))) {
					existing.glosa = `Pago de ${conceptoVal2} con Recibo: ${existing.recibo ?? ''}`.trim();
				}
				existing.importe = (Number(existing.importe) || 0) + (Number(r?.monto || 0) || 0);
				existing.rawItems.push(r);
			}
		}
		this.cobros = Array.from(byRecibo.values()).sort((a: any, b: any) => (new Date(b.fecha).getTime() - new Date(a.fecha).getTime()));
	}

	private resetResumen(): void {
		this.student = { ap_paterno: '', ap_materno: '', nombres: '', pensum: '', gestion: '', grupos: [] };
		this.gestionesCatalogo = [];
		this.noInscrito = false;
		this.cobros = [];
	}

	limpiarFormulario(): void {
		this.searchForm.reset();
		this.resetResumen();
	}

	private buildGlosaFallback(r: any): string {
		const conceptoVal = (r?.concepto ?? '').toString().trim();
		if (conceptoVal) {
			const nroRtxt = r?.nro_recibo ? ` ${r.nro_recibo}` : '';
			return `Pago de ${conceptoVal} con Recibo: ${nroRtxt}`.trim();
		}
		const nroF = r?.nro_factura ? `Factura: ${r.nro_factura}` : '';
		const nroR = r?.nro_recibo ? `Recibo: ${r.nro_recibo}` : '';
		const parts = [nroF, nroR].filter(s => !!s);
		return parts.length ? `Pago según ${parts.join(' / ')}` : 'Pago registrado';
	}

	// Mostrar en tabla: construye el detalle de factura en una tarjeta inferior
	mostrarItems(): void {
		if (this.isObsInvalid()) return;
		const seleccionados = this.cobros.filter(c => c.selected).flatMap(c => c.rawItems || []);
		const rows = (seleccionados || []).map((r: any) => {
			const monto = Number(r?.monto || 0) || 0;
			const descuento = Number(r?.descuento || 0) || 0;
			const puRaw = Number(r?.pu_mensualidad || 0) || 0;
			const pu = puRaw > 0 ? puRaw : monto;
			const cantidad = 1;
			const detalle = (r?.concepto || (r?.id_item ? 'Item' : 'Mensualidad') || '').toString();
			// En Facturación posterior, el Sub total debe mostrar lo pagado (monto), no restar descuento
			const subtotal = monto;
			const m = (r?.medio_doc || '').toString();
			// Documento (D) debe ser 'F' en esta pantalla (Factura)
			const d = 'F';
			// Observaciones globales digitadas en la pantalla
			const obs = (this.observaciones || '').toString();
			return { cantidad, detalle, pu, descuento, subtotal, m, d, obs };
		});
		this.detalleFactura = rows;
		this.detalleFacturaTotales.subtotal = rows.reduce((acc, x) => acc + (Number(x?.subtotal || 0) || 0), 0);
		this.detalleFacturaVisible = true;
		this.locked = true; // bloquear selección y observaciones
	}

	reponerFactura(): void {
		try {
			const selRow = this.cobros.find(c => !!c.selected);
			if (!selRow) return;
			const raw = Array.isArray(selRow.rawItems) ? selRow.rawItems : [];
			if (raw.length === 0) return;

			// Usar datos del primer item como referencia
			const anyIt: any = raw[0] || {};
			const idUsuario = Number(anyIt?.id_usuario || 0) || 1;
			const idForma = (anyIt?.id_forma_cobro || '1').toString();
			const now = new Date();
			const fechaIso = now.toISOString().slice(0, 19).replace('T', ' '); // yyyy-mm-dd HH:MM:SS

			// Construir items para batchStore evitando tocar asignaciones/cuotas
			const items = raw.map((r: any) => ({
				monto: Number(r?.monto || 0) || 0,
				fecha_cobro: fechaIso,
				pu_mensualidad: Number(r?.pu_mensualidad || 0) || 0,
				descuento: Number(r?.descuento || 0) || 0,
				order: r?.order ?? null,
				id_item: r?.id_item ?? null, // mantener si era item; no enviar id_asignacion_costo/id_cuota
				tipo_documento: 'F',
				medio_doc: 'C',
				concepto: (r?.concepto || '').toString(),
				observaciones: (this.observaciones || '').toString(),
				reposicion_factura: true,
			}));

			const payload: any = {
				cod_ceta: this.searchForm.value?.cod_ceta || '',
				cod_pensum: this.ctxCodPensum,
				tipo_inscripcion: this.ctxTipoInscripcion,
				cod_inscrip: this.ctxCodInscrip,
				gestion: this.ctxGestion,
				id_usuario: idUsuario,
				id_forma_cobro: idForma,
				emitir_online: true,
				items,
			};

			this.cobrosService.batchStore(payload).subscribe({
				next: (res: any) => {
					if (res?.success) {
						alert('Reposición registrada correctamente.');
						this.detalleFacturaVisible = false;
						this.locked = false;
						this.buscarPorCodCeta();
					} else {
						alert(res?.message || 'No se pudo registrar la reposición.');
					}
				},
				error: (err) => {
					console.error('Error reponerFactura', err);
					alert('Error al registrar la reposición.');
				}
			});
		} catch (e) {
			console.error(e);
		}
	}

	openDetalle(row: { glosa: string; gestion: string; fecha: string; factura?: number | null; recibo?: number | null; importe: number; usuario: string; selected: boolean; rawItems: any[]; }): void {
		this.detalleRow = row;
		this.detalleItems = Array.isArray(row?.rawItems) ? row.rawItems : [];
		const modalEl = document.getElementById('detalleReciboModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			const modal = new (window as any).bootstrap.Modal(modalEl);
			modal.show();
		}
	}

	cerrarDetalle(): void {
		const modalEl = document.getElementById('detalleReciboModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			const modal = (window as any).bootstrap.Modal.getInstance(modalEl);
			if (modal) modal.hide();
		}
		this.detalleRow = null;
		this.detalleItems = [];
	}

	// Utilidad para template (evitar arrow functions en bindings)
	hasSelection(): boolean {
		return this.cobros.some(c => !!c.selected);
	}

	isObsInvalid(): boolean {
		const txt = (this.observaciones || '').toString().trim();
		return txt.length === 0;
	}

	onToggleSel(row: any, idx: number): void {
		if (this.locked) return;
		this.cobros.forEach((c, i) => { c.selected = (i === idx) ? !c.selected : false; });
	}
}
