import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
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
	identidadForm: FormGroup;
	modalIdentidadForm: FormGroup;
	observaciones: string = '';
	
	// Estado UI para modal de Razón Social
	razonSocialEditable = false;
	modalAlertMessage = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'success';
	docLabel = 'CI';
	docPlaceholder = 'Introduzca CI';
	showComplemento = true;
	loading = false;
	
	// Catálogo de tipos de documento de identidad
	sinDocsIdentidad: Array<{ codigo: number; descripcion: string }> = [
		{ codigo: 1, descripcion: 'CI - CEDULA DE IDENTIDAD' },
		{ codigo: 2, descripcion: 'CEX - CEDULA DE IDENTIDAD DE EXTRANJERO' },
		{ codigo: 3, descripcion: 'PAS - PASAPORTE' },
		{ codigo: 4, descripcion: 'OD - OTRO DOCUMENTO DE IDENTIDAD' },
		{ codigo: 5, descripcion: 'NIT - NUMERO DE IDENTIFICACION TRIBUTARIA' }
	];
	// Contexto para batchStore
	private ctxCodPensum: string = '';
	private ctxTipoInscripcion: string = '';
	private ctxCodInscrip: number | null = null;
	private ctxGestion: string = '';
	private currentUserNickname: string = '';
	// Datos del cliente (estudiante) para facturación
	private clienteData: { numero: string; razon: string; tipo_identidad: number } = { numero: '', razon: '', tipo_identidad: 5 };

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
	detalleFactura: Array<{ cantidad: number; detalle: string; pu: number; descuento: number; subtotal: number; m?: string; d?: string; obs?: string; }> = [];
	detalleFacturaTotales: { subtotal: number } = { subtotal: 0 };

	constructor(private fb: FormBuilder, private cobrosService: CobrosService) {
		this.searchForm = this.fb.group({ cod_ceta: [''], gestion: [''] });
		
		// Formulario de identidad (readonly, se abre modal al hacer clic)
		this.identidadForm = this.fb.group({
			nombre_completo: [''],
			tipo_identidad: [5],
			ci: [''],
			complemento_habilitado: [false],
			complemento_ci: [{ value: '', disabled: true }],
			razon_social: ['']
		});
		
		// Formulario del modal (separado para no sincronizar hasta guardar)
		this.modalIdentidadForm = this.fb.group({
			tipo_identidad: [5, Validators.required],
			ci: [''],
			complemento_habilitado: [false],
			complemento_ci: [{ value: '', disabled: true }],
			razon_social: ['']
		});
		// Obtener usuario actual del localStorage (current_user)
		try {
			const userData = localStorage.getItem('current_user');
			if (userData) {
				const user = JSON.parse(userData);
				this.currentUserNickname = user?.nickname || user?.nombre_completo || user?.nombre || 'Sistema';
			}
		} catch (e) {
			this.currentUserNickname = 'Sistema';
		}
	}

	ngOnInit(): void {
		// Suscripciones del modal: actualizar UI del documento según tipo
		this.modalIdentidadForm.get('tipo_identidad')?.valueChanges.subscribe((v: number) => {
			this.updateModalTipoUI(Number(v || 5));
		});
		this.updateModalTipoUI(Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 5));
		
		// Habilitar/deshabilitar complemento CI en el modal
		this.modalIdentidadForm.get('complemento_habilitado')?.valueChanges.subscribe((v: boolean) => {
			const ctrl = this.modalIdentidadForm.get('complemento_ci');
			if (v) ctrl?.enable(); else ctrl?.disable();
		});
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
		
		// Guardar datos del cliente para facturación
		const codCeta = String(est?.cod_ceta || this.searchForm.value?.cod_ceta || '');
		const nombreCompleto = `${est?.ap_paterno || ''} ${est?.ap_materno || ''} ${est?.nombres || ''}`.trim() || 'S/N';
		
		// Obtener CI del estudiante (igual que en Cobros)
		let ciEstudiante = String(est?.ci || '');
		let tipoIdentidad = 1; // Por defecto CI
		
		// Autocompletar desde documento_identidad si existe en el resumen
		const docId = res?.data?.documento_identidad || null;
		if (docId) {
			tipoIdentidad = Number(docId?.tipo_identidad || 0) || 1;
			ciEstudiante = String(docId?.numero || ciEstudiante);
		} else if (!ciEstudiante) {
			ciEstudiante = 'SIN INFORMACIÓN';
		}
		
		// clienteData ya no se usa para facturación, se obtiene de identidadForm
		this.clienteData = {
			numero: ciEstudiante,  // CI del estudiante, no cod_ceta
			razon: nombreCompleto,
			tipo_identidad: tipoIdentidad
		};
		
		// Actualizar formulario de identidad
		this.identidadForm.patchValue({
			nombre_completo: nombreCompleto,
			tipo_identidad: tipoIdentidad,
			ci: ciEstudiante,
			complemento_habilitado: false,
			complemento_ci: '',
			razon_social: est?.ap_paterno || nombreCompleto
		});

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
			// Filtrar solo RECIBOS (tipo_documento='R') con reposicion_factura = 1
			const tipoDoc = String(r?.tipo_documento || '').toUpperCase();
			if (tipoDoc === 'R' && (r?.reposicion_factura == 1 || r?.reposicion_factura === true)) {
				continue;
			}
			
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

			// Obtener nro_recibo del primer item
			const anyIt: any = raw[0] || {};
			const nroRecibo = anyIt?.nro_recibo ? Number(anyIt.nro_recibo) : null;
			
			if (!nroRecibo) {
				alert('No se pudo identificar el número de recibo.');
				return;
			}

			const idForma = (anyIt?.id_forma_cobro || '1').toString();
			const now = new Date();
			const fechaIso = now.toISOString().slice(0, 19).replace('T', ' ');

			// Agregar "Generado por [usuario]" a las observaciones
			const obsBase = (this.observaciones || '').toString().trim();
			const obsConGeneradoPor = obsBase ? `${obsBase}. Generado por ${this.currentUserNickname}` : `Generado por ${this.currentUserNickname}`;

			// Construir items para la factura nueva (CON reposicion_factura, SIN id_asignacion_costo/id_cuota)
			const items = raw.map((r: any) => ({
				monto: Number(r?.monto || 0) || 0,
				fecha_cobro: fechaIso,
				pu_mensualidad: Number(r?.pu_mensualidad || 0) || 0,
				descuento: Number(r?.descuento || 0) || 0,
				order: r?.order ?? null,
				id_item: r?.id_item ?? null,
				tipo_documento: 'F',
				medio_doc: 'C',
				concepto: (r?.concepto || '').toString(),
				observaciones: obsConGeneradoPor,
				reposicion_factura: 1
			}));

			// Obtener datos del cliente desde identidadForm (puede haber sido modificado en modal)
			const tipoId = Number(this.identidadForm.get('tipo_identidad')?.value || 1);
			const ci = String(this.identidadForm.get('ci')?.value || '');
			const razonSocial = String(this.identidadForm.get('razon_social')?.value || '');
			const complementoHab = !!this.identidadForm.get('complemento_habilitado')?.value;
			const complemento = complementoHab ? String(this.identidadForm.get('complemento_ci')?.value || '') : '';
			
			// Construir número de documento completo (CI + complemento si aplica)
			const numeroDocumento = complemento ? `${ci}${complemento}` : ci;

			// Obtener id_usuario desde localStorage
			let idUsuario = 0;
			if (typeof localStorage !== 'undefined') {
				try {
					const raw = localStorage.getItem('current_user');
					if (raw) {
						const parsed = JSON.parse(raw);
						if (parsed?.id_usuario) {
							idUsuario = Number(parsed.id_usuario);
						}
					}
				} catch (e) {
					console.error('Error obteniendo id_usuario', e);
				}
			}

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
				use_reposicion_user: true,
				cliente: {
					numero: numeroDocumento,
					razon: razonSocial,
					tipo_identidad: tipoId
				}
			};

			// Paso 1: Marcar el recibo original como repuesto
			this.cobrosService.marcarReciboRepuesto(nroRecibo).subscribe({
				next: (res: any) => {
					if (res?.success) {
						// Paso 2: Crear la factura nueva con id_usuario=37
						this.cobrosService.batchStore(payload).subscribe({
							next: (res2: any) => {
								if (res2?.success) {
									alert('Reposición registrada correctamente.');
									
									// Descargar la factura generada
									const items = res2?.data?.items || [];
									if (items.length > 0) {
										// Buscar el primer item que sea factura (tipo_documento='F')
										const facturaItem = items.find((it: any) => String(it?.tipo_documento || '').toUpperCase() === 'F');
										
										if (facturaItem && facturaItem.nro_factura) {
											const nroFactura = facturaItem.nro_factura;
											// Obtener año de la fecha de cobro o usar año actual
											const fechaCobro = facturaItem?.cobro?.fecha_cobro || facturaItem?.cobro?.created_at;
											const anio = fechaCobro ? new Date(fechaCobro).getFullYear() : new Date().getFullYear();
											
											this.cobrosService.downloadFacturaPdf(anio, nroFactura).subscribe({
												next: (blob: Blob) => {
													const url = window.URL.createObjectURL(blob);
													const a = document.createElement('a');
													a.href = url;
													a.download = `Factura_${nroFactura}_${anio}.pdf`;
													document.body.appendChild(a);
													a.click();
													document.body.removeChild(a);
													window.URL.revokeObjectURL(url);
												},
												error: (err) => {
													console.error('Error descargando factura', err);
												}
											});
										}
									}
									
									this.detalleFacturaVisible = false;
									this.locked = false;
									this.buscarPorCodCeta();
								} else {
									alert(res2?.message || 'No se pudo registrar la reposición.');
								}
							},
							error: (err) => {
								console.error('Error creando factura', err);
								alert('Error al crear la factura.');
							}
						});
					} else {
						alert(res?.message || 'No se pudo marcar el recibo.');
					}
				},
				error: (err) => {
					console.error('Error marcando recibo', err);
					alert('Error al marcar el recibo como repuesto.');
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

	// ============ MÉTODOS PARA MODAL DE RAZÓN SOCIAL ============

	openRazonSocialModal(): void {
		const modalEl = document.getElementById('razonSocialModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			this.modalAlertMessage = '';
			const tipo = Number(this.identidadForm.get('tipo_identidad')?.value || 5);
			this.modalIdentidadForm.patchValue({
				tipo_identidad: tipo,
				ci: this.identidadForm.get('ci')?.value || '',
				complemento_habilitado: !!this.identidadForm.get('complemento_habilitado')?.value,
				complemento_ci: this.identidadForm.get('complemento_ci')?.value || '',
				razon_social: this.identidadForm.get('razon_social')?.value || ''
			}, { emitEvent: false });
			this.updateModalTipoUI(tipo);
			this.razonSocialEditable = false;
			const modal = new (window as any).bootstrap.Modal(modalEl);
			modal.show();
		}
	}

	updateModalTipoUI(tipo: number): void {
		if (tipo === 1) {
			this.docLabel = 'CI';
			this.docPlaceholder = 'Introduzca CI';
			this.showComplemento = true;
		} else if (tipo === 2) {
			this.docLabel = 'CEX';
			this.docPlaceholder = 'Introduzca CEX';
			this.showComplemento = false;
		} else if (tipo === 3) {
			this.docLabel = 'Pasaporte';
			this.docPlaceholder = 'Introduzca Pasaporte';
			this.showComplemento = false;
		} else if (tipo === 4) {
			this.docLabel = 'Otro Documento';
			this.docPlaceholder = 'Introduzca documento';
			this.showComplemento = false;
		} else {
			this.docLabel = 'NIT';
			this.docPlaceholder = 'Introduzca NIT';
			this.showComplemento = false;
		}
	}

	buscarPorCI(): void {
		const ci = (this.modalIdentidadForm.get('ci')?.value || '').toString().trim();
		const tipoId = Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 5);
		if (!ci) {
			this.showModalAlert('Ingrese el número de documento para buscar', 'warning');
			return;
		}
		this.loading = true;
		this.cobrosService.buscarRazonSocial(ci, tipoId).subscribe({
			next: (res) => {
				if (res?.success && res?.data) {
					const data = res.data;
					const tipoEncontrado = Number(data.id_tipo_doc_identidad || tipoId);
					if (tipoEncontrado && tipoEncontrado !== tipoId) {
						this.modalIdentidadForm.patchValue({ tipo_identidad: tipoEncontrado }, { emitEvent: true });
						this.updateModalTipoUI(tipoEncontrado);
						this.showModalAlert('Documento encontrado con un tipo diferente. Se ajustó automáticamente el tipo.', 'warning');
					}
					this.modalIdentidadForm.patchValue({
						razon_social: data.razon_social || '',
						complemento_ci: data.complemento || ''
					});
					this.razonSocialEditable = false;
				} else {
					this.razonSocialEditable = true;
					this.modalIdentidadForm.patchValue({ razon_social: '' }, { emitEvent: false });
					this.identidadForm.patchValue({ razon_social: '' }, { emitEvent: false });
					this.showModalAlert('No existe registro, puede ingresar la razón social y guardar', 'warning');
				}
				this.loading = false;
			},
			error: () => {
				this.loading = false;
				this.showModalAlert('Error al buscar', 'error');
			}
		});
	}

	guardarRazonSocial(): void {
		const ci = (this.modalIdentidadForm.get('ci')?.value || '').toString().trim();
		const tipoId = Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 5);
		const razon = (this.modalIdentidadForm.get('razon_social')?.value || '').toString().trim();
		const complemento = (this.modalIdentidadForm.get('complemento_ci')?.value || '').toString().trim() || null;
		if (!ci) {
			this.showModalAlert('El número de documento es obligatorio', 'warning');
			return;
		}
		if (!razon) {
			this.showModalAlert('La razón social es obligatoria', 'warning');
			return;
		}
		this.loading = true;
		this.cobrosService.guardarRazonSocial({ nit: ci, tipo_id: tipoId, razon_social: razon || null, complemento }).subscribe({
			next: (res) => {
				if (res?.success) {
					this.showModalAlert('Razón social guardada', 'success');
					this.razonSocialEditable = false;
					this.identidadForm.patchValue({
						tipo_identidad: tipoId,
						ci: ci,
						complemento_habilitado: !!this.modalIdentidadForm.get('complemento_habilitado')?.value,
						complemento_ci: complemento || '',
						razon_social: razon || (res?.data?.razon_social || '')
					}, { emitEvent: false });
					
					// Actualizar clienteData con los nuevos valores
					this.clienteData = {
						numero: ci,
						razon: razon,
						tipo_identidad: tipoId
					};
					
					const modalEl = document.getElementById('razonSocialModal');
					if (modalEl && (window as any).bootstrap?.Modal) {
						const modal = (window as any).bootstrap.Modal.getInstance(modalEl);
						if (modal) modal.hide();
					}
				} else {
					this.showModalAlert(res?.message || 'Error al guardar', 'error');
				}
				this.loading = false;
			},
			error: () => {
				this.loading = false;
				this.showModalAlert('Error al guardar', 'error');
			}
		});
	}

	editRazonSocial(): void {
		this.razonSocialEditable = true;
		this.modalAlertMessage = '';
	}

	showModalAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.modalAlertMessage = message;
		this.modalAlertType = type;
	}
}
