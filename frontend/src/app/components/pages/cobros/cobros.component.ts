import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';

@Component({
	selector: 'app-cobros-page',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './cobros.component.html',
	styleUrls: ['./cobros.component.scss']
})
export class CobrosComponent implements OnInit {
	// Estado UI
	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';
	razonSocialEditable = false;
	// Alertas del modal Razón Social
	modalAlertMessage = '';
	modalAlertType: 'success' | 'error' | 'warning' = 'success';
	// UI dinámico del documento en el modal
	docLabel = 'CI';
	docPlaceholder = 'Introduzca CI';
	showComplemento = true;

	// Formularios
	searchForm: FormGroup;
	batchForm: FormGroup;
	identidadForm: FormGroup;

	// Datos
	resumen: any = null;
	gestiones: any[] = [];
	formasCobro: any[] = [];
	pensums: any[] = [];

	constructor(
		private fb: FormBuilder,
		private cobrosService: CobrosService
	) {
		this.searchForm = this.fb.group({
			cod_ceta: ['', Validators.required],
			gestion: ['']
		});

		this.batchForm = this.fb.group({
			cabecera: this.fb.group({
				cod_ceta: ['', Validators.required],
				cod_pensum: ['', Validators.required],
				tipo_inscripcion: ['', Validators.required],
				gestion: [''],
				id_forma_cobro: ['', Validators.required],
				id_cuentas_bancarias: [''],
				id_usuario: ['', Validators.required]
			}),
			pagos: this.fb.array([])
		});

		// UI: Identidad/Razón social (no se envía al backend)
		this.identidadForm = this.fb.group({
			nombre_completo: [''],
			tipo_identidad: [1, Validators.required], // 1=CI,2=CEX,3=PAS,4=OD,5=NIT
			ci: [''],
			complemento_habilitado: [false],
			complemento_ci: [{ value: '', disabled: true }],
			razon_social: [''],
			email_habilitado: [false],
			email: [{ value: '', disabled: true }, [Validators.email]],
			turno: ['']
		});
	}

	ngOnInit(): void {
		// Actualizar UI del documento según tipo
		this.identidadForm.get('tipo_identidad')?.valueChanges.subscribe((v: number) => {
			this.updateTipoUI(Number(v || 1));
		});
		this.updateTipoUI(Number(this.identidadForm.get('tipo_identidad')?.value || 1));
		// Habilitar/deshabilitar complemento CI
		this.identidadForm.get('complemento_habilitado')?.valueChanges.subscribe((v: boolean) => {
			const ctrl = this.identidadForm.get('complemento_ci');
			if (v) ctrl?.enable(); else ctrl?.disable();
		});

		// Habilitar/deshabilitar edición de email
		this.identidadForm.get('email_habilitado')?.valueChanges.subscribe((v: boolean) => {
			const ctrl = this.identidadForm.get('email');
			if (v) ctrl?.enable(); else ctrl?.disable();
		});

		// Cargar catálogos
		this.cobrosService.getGestionesActivas().subscribe({
			next: (res) => { if (res.success) this.gestiones = res.data; },
			error: () => {}
		});
		this.cobrosService.getFormasCobro().subscribe({
			next: (res) => { if (res.success) this.formasCobro = res.data; },
			error: () => {}
		});
	}

	// Helpers
	get pagos(): FormArray {
		return this.batchForm.get('pagos') as FormArray;
	}

	addPago(): void {
		this.pagos.push(this.fb.group({
			nro_cobro: ['', Validators.required],
			id_cuota: [null],
			id_item: [null],
			monto: [null, [Validators.required, Validators.min(0)]],
			fecha_cobro: ['', Validators.required],
			observaciones: ['']
		}));
	}

	removePago(i: number): void {
		this.pagos.removeAt(i);
	}

	// Acciones
	loadResumen(): void {
		if (!this.searchForm.valid) return;
		this.loading = true;
		const { cod_ceta, gestion } = this.searchForm.value;
		this.cobrosService.getResumen(cod_ceta, gestion).subscribe({
			next: (res) => {
				if (res.success) {
					this.resumen = res.data;
					this.showAlert('Resumen cargado', 'success');
					// Prefill identidad/razón social
					const est = this.resumen?.estudiante || {};
					const fullName = [est.nombres, est.ap_paterno, est.ap_materno].filter(Boolean).join(' ');
					this.identidadForm.patchValue({
						nombre_completo: fullName,
						tipo_identidad: 1,
						ci: est.ci || '',
						complemento_habilitado: false,
						complemento_ci: '',
						razon_social: est.ap_paterno || fullName,
						email_habilitado: false,
						email: est.email || ''
					});

					// Prefill cabecera del batch
					const ins = this.resumen?.inscripcion || {};
					(this.batchForm.get('cabecera') as FormGroup).patchValue({
						cod_ceta: est.cod_ceta || cod_ceta,
						// Priorizar datos desde inscripciones
						cod_pensum: ins.cod_pensum ?? est.cod_pensum ?? '',
						tipo_inscripcion: ins.tipo_inscripcion || '',
						gestion: this.resumen?.gestion ?? ins.gestion ?? gestion ?? ''
					});

					// Cargar pensums por carrera desde el pensum del estudiante
					const pensumRel = est?.pensum || {};
					const codigoCarrera = pensumRel?.codigo_carrera;
					if (codigoCarrera) {
						this.cobrosService.getPensumsByCarrera(codigoCarrera).subscribe({
							next: (pRes) => {
								if (pRes.success) this.pensums = pRes.data;
							},
							error: () => {}
						});
					} else {
						this.pensums = [];
					}
				} else {
					this.resumen = null;
					this.showAlert(res.message || 'No se pudo obtener el resumen', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Resumen error:', err);
				this.resumen = null;
				this.showAlert('Error al obtener resumen', 'error');
				this.loading = false;
			}
		});
	}

	openKardexModal(): void {
		const modalEl = document.getElementById('kardexModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			const modal = new (window as any).bootstrap.Modal(modalEl);
			modal.show();
		}
	}

	openRazonSocialModal(): void {
		const modalEl = document.getElementById('razonSocialModal');
		if (modalEl && (window as any).bootstrap?.Modal) {
			// limpiar alertas del modal en cada apertura
			this.modalAlertMessage = '';
			const modal = new (window as any).bootstrap.Modal(modalEl);
			modal.show();
		}
	}

	// Buscar por Cod. CETA desde la cabecera del lote reutilizando loadResumen()
	buscarPorCodCetaCabecera(): void {
		const cabecera = this.batchForm.get('cabecera') as FormGroup;
		const cod_ceta = cabecera?.get('cod_ceta')?.value;
		const gestion = cabecera?.get('gestion')?.value || '';
		if (!cod_ceta) {
			this.showAlert('Ingrese el Codigo CETA para buscar', 'warning');
			return;
		}
		// Reutiliza el formulario de búsqueda y la lógica existente
		this.searchForm.patchValue({ cod_ceta, gestion });
		this.loadResumen();
	}

	buscarPorCI(): void {
		const ci = (this.identidadForm.get('ci')?.value || '').toString().trim();
		const tipoId = Number(this.identidadForm.get('tipo_identidad')?.value || 1);
		if (!ci) {
			this.showModalAlert('Ingrese el número de documento para buscar', 'warning');
			return;
		}
		this.loading = true;
		this.cobrosService.buscarRazonSocial(ci, tipoId).subscribe({
			next: (res) => {
				const data = res?.data || null;
				if (data) {
					// Encontrado: autocompletar y bloquear edición
					this.identidadForm.patchValue({
						razon_social: data.razon_social || '',
						complemento_ci: data.complemento || ''
					});
					this.razonSocialEditable = false;
					this.showModalAlert('Razón social encontrada', 'success');
				} else {
					// No encontrado: habilitar edición de razón social
					this.razonSocialEditable = true;
					this.showModalAlert('No existe registro, puede ingresar la razón social y guardar', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Buscar Razón Social error:', err);
				this.showModalAlert('Error al buscar razón social', 'error');
				this.loading = false;
			}
		});
	}

	guardarRazonSocial(): void {
		const ci = (this.identidadForm.get('ci')?.value || '').toString().trim();
		const tipoId = Number(this.identidadForm.get('tipo_identidad')?.value || 1);
		const razon = (this.identidadForm.get('razon_social')?.value || '').toString().trim();
		const complemento = (this.identidadForm.get('complemento_ci')?.value || '').toString().trim() || null;
		if (!ci) {
			this.showModalAlert('El número de documento es obligatorio', 'warning');
			return;
		}
		if (this.razonSocialEditable && !razon) {
			this.showModalAlert('Ingrese la razón social', 'warning');
			return;
		}
		this.loading = true;
		this.cobrosService.guardarRazonSocial({ nit: ci, tipo_id: tipoId, razon_social: razon || null, complemento }).subscribe({
			next: (res) => {
				if (res?.success) {
					this.showModalAlert('Razón social guardada', 'success');
					this.razonSocialEditable = false;
					// Cerrar modal si existe instancia de Bootstrap
					const modalEl = document.getElementById('razonSocialModal');
					const bs = (window as any).bootstrap;
					if (modalEl && bs?.Modal) {
						const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
						instance.hide();
					}
				} else {
					this.showModalAlert(res?.message || 'No se pudo guardar', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Guardar Razón Social error:', err);
				const msg = err?.error?.message || 'Error al guardar razón social';
				this.showModalAlert(msg, 'error');
				this.loading = false;
			}
		});
	}

	// Cambiar a edición manual de razón social
	editRazonSocial(): void {
		this.razonSocialEditable = true;
	}

	// Ajusta labels/placeholders y complemento por tipo
	private updateTipoUI(tipoId: number): void {
		switch (tipoId) {
			case 1:
				this.docLabel = 'CI';
				this.docPlaceholder = 'Introduzca CI';
				this.showComplemento = true;
				break;
			case 2:
				this.docLabel = 'CEX';
				this.docPlaceholder = 'Introduzca cédula de extranjería';
				this.showComplemento = false;
				break;
			case 3:
				this.docLabel = 'PAS';
				this.docPlaceholder = 'Introduzca pasaporte';
				this.showComplemento = false;
				break;
			case 4:
				this.docLabel = 'OD';
				this.docPlaceholder = 'Introduzca otro documento';
				this.showComplemento = false;
				break;
			case 5:
				this.docLabel = 'NIT';
				this.docPlaceholder = 'Introduzca NIT';
				this.showComplemento = false;
				break;
			default:
				this.docLabel = 'CI';
				this.docPlaceholder = 'Introduzca CI';
				this.showComplemento = true;
		}
		// Resetear y deshabilitar complemento cuando no aplique
		if (!this.showComplemento) {
			this.identidadForm.patchValue({ complemento_habilitado: false, complemento_ci: '' }, { emitEvent: false });
			this.identidadForm.get('complemento_ci')?.disable({ emitEvent: false });
		}
	}

	private showModalAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.modalAlertMessage = message;
		this.modalAlertType = type;
		setTimeout(() => (this.modalAlertMessage = ''), 4000);
	}

	submitBatch(): void {
		if (!this.batchForm.valid || this.pagos.length === 0) {
			this.showAlert('Complete los datos y agregue al menos un pago', 'warning');
			return;
		}
		this.loading = true;
		const { cabecera, pagos } = this.batchForm.value;
		const payload = { ...cabecera, pagos };
		this.cobrosService.batchStore(payload).subscribe({
			next: (res) => {
				if (res.success) {
					this.showAlert('Cobros registrados', 'success');
					this.batchForm.reset({ cabecera: {}, pagos: [] });
					(this.batchForm.get('pagos') as FormArray).clear();
				} else {
					this.showAlert(res.message || 'No se pudo registrar', 'warning');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Batch error:', err);
				const msg = err?.error?.message || 'Error al registrar cobros';
				this.showAlert(msg, 'error');
				this.loading = false;
			}
		});
	}

	private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => (this.alertMessage = ''), 4000);
	}
}
