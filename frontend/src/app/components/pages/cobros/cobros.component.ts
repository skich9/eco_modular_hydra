import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { MensualidadModalComponent } from './mensualidad-modal/mensualidad-modal.component';

@Component({
  selector: 'app-cobros-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, MensualidadModalComponent],
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
  modalIdentidadForm: FormGroup;

  // Mensualidades
  mensualidadModalForm: FormGroup;
  mensualidadesPendientes = 0;
  mensualidadPU = 0;
  // Tipo de modal activo
  modalTipo: 'mensualidad' | 'rezagado' | 'recuperacion' = 'mensualidad';

  // Datos
  resumen: any = null;
  gestiones: any[] = [];
  formasCobro: any[] = [];
  sinDocsIdentidad: Array<{ codigo: number; descripcion: string }> = [];
  pensums: any[] = [];
  cuentasBancarias: any[] = [];
  // Visibilidad del card de Opciones de cobro
  showOpciones = false;

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

    // Formulario del modal (separado para no sincronizar hasta guardar)
    this.modalIdentidadForm = this.fb.group({
      tipo_identidad: [1, Validators.required],
      ci: [''],
      complemento_habilitado: [false],
      complemento_ci: [{ value: '', disabled: true }],
      razon_social: ['']
    });

    // Modal de Mensualidades
    this.mensualidadModalForm = this.fb.group({
      metodo_pago: [''],
      cantidad: [1, [Validators.required, Validators.min(1)]],
      costo_total: [{ value: 0, disabled: true }],
      observaciones: ['']
    });
  }

  private esFormaEfectivoById(val: any): boolean {
    const match = this.formasCobro.find((f: any) => `${f?.id_forma_cobro}` === `${val}`);
    const nombre = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre === 'EFECTIVO';
  }

  private esFormaTarjetaById(val: any): boolean {
    const match = this.formasCobro.find((f: any) => `${f?.id_forma_cobro}` === `${val}`);
    const nombre = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre === 'TARJETA';
  }

  private ensureMetodoPagoPermitido(permitidos: string[]): boolean {
    const ctrl = this.batchForm.get('cabecera.id_forma_cobro');
    const value = (ctrl as any)?.value;
    if (value === null || value === undefined || value === '') {
      ctrl?.markAsTouched();
      ctrl?.updateValueAndValidity();
      this.showAlert('Seleccione un método de pago para continuar', 'warning');
      return false;
    }
    const nombreOk = (() => {
      const match = this.formasCobro.find((f: any) => `${f?.id_forma_cobro}` === `${value}`);
      const raw = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
      // Normalizar acentos
      const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      const permitidosNorm = permitidos.map(p => p.toUpperCase()).map(p => p.normalize('NFD').replace(/\p{Diacritic}/gu, ''));
      return permitidosNorm.includes(nombre);
    })();
    if (!nombreOk) {
      ctrl?.markAsTouched();
      const current = ctrl?.errors || {};
      ctrl?.setErrors({ ...current, metodoNoPermitido: true });
      this.showAlert(`Por ahora solo se permite: ${permitidos.join(', ')}`, 'warning');
      return false;
    }
    if (ctrl?.errors) {
      const errs = { ...ctrl.errors } as any;
      if ('soloEfectivo' in errs) delete errs['soloEfectivo'];
      if ('metodoNoPermitido' in errs) delete errs['metodoNoPermitido'];
      if (value !== null && value !== undefined && value !== '' && 'required' in errs) delete errs['required'];
      ctrl.setErrors(Object.keys(errs).length ? errs : null);
    }
    return true;
  }

  private clearSoloEfectivoErrorIfMatches(): void {
    const ctrl = this.batchForm.get('cabecera.id_forma_cobro');
    if (!ctrl) return;
    const value = (ctrl as any).value;
    if (value === null || value === undefined || value === '') return;
    const esEfectivo = this.esFormaEfectivoById(value);
    if (esEfectivo && ctrl.errors) {
      const errs = { ...(ctrl.errors as any) };
      if ('soloEfectivo' in errs) {
        delete errs['soloEfectivo'];
        ctrl.setErrors(Object.keys(errs).length ? errs : null);
      }
    }
    ctrl.updateValueAndValidity({ emitEvent: false });
  }

  ngOnInit(): void {
    // Suscripciones del modal: actualizar UI del documento según tipo
    this.modalIdentidadForm.get('tipo_identidad')?.valueChanges.subscribe((v: number) => {
      this.updateModalTipoUI(Number(v || 1));
    });
    this.updateModalTipoUI(Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 1));
    // Habilitar/deshabilitar complemento CI en el modal
    this.modalIdentidadForm.get('complemento_habilitado')?.valueChanges.subscribe((v: boolean) => {
      const ctrl = this.modalIdentidadForm.get('complemento_ci');
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
    // Documentos de identidad desde SIN
    this.cobrosService.getSinDocumentosIdentidad().subscribe({
      next: (res) => {
        if (res.success) {
          const arr = Array.isArray(res.data) ? res.data : [];
          this.sinDocsIdentidad = arr
            .map((d: any) => ({
              codigo: Number(d?.codigo_clasificador ?? d?.codigo ?? 0),
              descripcion: String(d?.descripcion ?? '')
            }))
            .filter((x: { codigo: number; descripcion: string }) => x.codigo > 0 && !!x.descripcion);
        } else {
          this.sinDocsIdentidad = [];
        }
      },
      error: () => { this.sinDocsIdentidad = []; }
    });
    this.cobrosService.getFormasCobro().subscribe({
      next: (res) => {
        if (res.success) {
          this.formasCobro = res.data;
          // Si ya hay un valor y corresponde a EFECTIVO, limpiar error custom
          this.clearSoloEfectivoErrorIfMatches();
        }
      },
      error: () => {}
    });
    // Cargar cuentas bancarias para métodos de pago como TARJETA/DEPÓSITO
    this.cobrosService.getCuentasBancarias().subscribe({
      next: (res) => { if (res.success) this.cuentasBancarias = res.data; },
      error: () => {}
    });

    // Recalcular costo total de mensualidades cuando cambie la cantidad
    this.mensualidadModalForm.get('cantidad')?.valueChanges.subscribe((v: number) => {
      this.recalcMensualidadTotal();
    });

    // Limpiar error custom cuando el método de pago sea EFECTIVO
    const formaCtrl = this.batchForm.get('cabecera.id_forma_cobro');
    formaCtrl?.valueChanges.subscribe((val) => {
      if (!formaCtrl) return;
      // Si el valor corresponde a EFECTIVO en el catálogo, limpiar error 'soloEfectivo'
      const esEfectivo = this.esFormaEfectivoById(val);
      if (formaCtrl.errors) {
        const errs = { ...formaCtrl.errors } as any;
        if (esEfectivo && 'soloEfectivo' in errs) delete errs['soloEfectivo'];
        if (val !== null && val !== undefined && val !== '' && 'required' in errs) delete errs['required'];
        formaCtrl.setErrors(Object.keys(errs).length ? errs : null);
      }
      // Forzar reevaluación de validators (required) al cambiar valor
      formaCtrl.updateValueAndValidity({ emitEvent: false });
    });
  }

  // Helpers
  get pagos(): FormArray {
    return this.batchForm.get('pagos') as FormArray;
  }

  get selectedFormaCobroId(): string {
    const v = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value;
    return (v === null || v === undefined) ? '' : `${v}`;
  }

  get isFormaCobroInvalid(): boolean {
    const ctrl = this.batchForm.get('cabecera.id_forma_cobro');
    return !!ctrl && ctrl.invalid && (ctrl.touched || ctrl.dirty);
  }

  private ensureMetodoPagoEfectivo(): boolean {
    const ctrl = this.batchForm.get('cabecera.id_forma_cobro');
    const value = (ctrl as any)?.value;
    if (value === null || value === undefined || value === '') {
      ctrl?.markAsTouched();
      // dejar que el Validators.required se encargue
      ctrl?.updateValueAndValidity();
      this.showAlert('Seleccione un método de pago para continuar', 'warning');
      return false;
    }
    // Buscar en catálogo por id y validar que sea EFECTIVO
    const esEfectivo = this.esFormaEfectivoById(value);
    if (!esEfectivo) {
      ctrl?.markAsTouched();
      // setear error custom para activar estilo inválido
      const current = ctrl?.errors || {};
      ctrl?.setErrors({ ...current, soloEfectivo: true });
      this.showAlert('Por ahora solo se permite el método de pago EFECTIVO', 'warning');
      return false;
    }
    // todo válido: limpiar errores residuales si existieran
    if (ctrl?.errors) {
      const errs = { ...ctrl.errors } as any;
      if ('soloEfectivo' in errs) delete errs['soloEfectivo'];
      if (value !== null && value !== undefined && value !== '' && 'required' in errs) delete errs['required'];
      ctrl.setErrors(Object.keys(errs).length ? errs : null);
    }
    return true;
  }

  addPago(): void {
    this.pagos.push(this.fb.group({
      // Backend-required/known fields
      nro_cobro: [''],
      id_cuota: [null],
      id_item: [null],
      monto: [null], // será calculado al enviar (subtotal)
      fecha_cobro: [''],
      observaciones: [''],
      descuento: [0],
      nro_factura: [null],
      nro_recibo: [null],
      pu_mensualidad: [0, [Validators.min(0)]],
      order: [0],
      // Campos UI para emular la tabla clásica
      cantidad: [1, [Validators.required, Validators.min(1)]],
      detalle: [''],
      m_marca: [false],
      d_marca: [false]
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
          this.showOpciones = true;
          this.showAlert('Resumen cargado', 'success');
          // Mostrar advertencias de backend si existen (fallback de gestión u otros)
          const warnings = (this.resumen?.warnings || []) as string[];
          if (Array.isArray(warnings) && warnings.length) {
            this.showAlert(warnings.join(' | '), 'warning');
          }
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

          // Autocompletar desde documentos presentados si el backend envió documento_identidad
          const docId = this.resumen?.documento_identidad || null;
          if (docId) {
            const tipo = Number(docId?.tipo_identidad || 0) || 1;
            const numero = (docId?.numero || '').toString();
            this.identidadForm.patchValue({
              tipo_identidad: tipo,
              ci: numero
            }, { emitEvent: false });
          } else {
            // Sin coincidencia: marcar sin información
            this.identidadForm.patchValue({ ci: 'SIN INFORMACIÓN' }, { emitEvent: false });
          }

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

          // Datos para Mensualidad: pendientes y precio unitario
          const nroCuotas = Number(this.resumen?.totales?.nro_cuotas || 0);
          const pagadas = Number(this.resumen?.cobros?.mensualidad?.count || 0);
          this.mensualidadesPendientes = Math.max(0, nroCuotas - pagadas);
          this.mensualidadPU = Number(this.resumen?.totales?.pu_mensual || 0);
          // Inicializar modal de mensualidades
          const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
          this.mensualidadModalForm.patchValue({
            metodo_pago: defaultMetodo,
            cantidad: this.mensualidadesPendientes > 0 ? 1 : 0,
            costo_total: this.mensualidadPU
          }, { emitEvent: false });
          this.recalcMensualidadTotal();
        } else {
          this.resumen = null;
          this.showOpciones = false;
          this.showAlert(res.message || 'No se pudo obtener el resumen', 'warning');
        }
        this.loading = false;
      },
      error: (err) => {
        console.error('Resumen error:', err);
        this.resumen = null;
        this.showOpciones = false;
        const status = Number(err?.status || 0);
        const backendMsg = (err?.error?.message || err?.message || '').toString();
        if (status === 404) {
          this.showAlert(backendMsg || 'Estudiante no encontrado', 'warning');
        } else if (status === 422) {
          // Mostrar errores de validación si existen
          const errors = err?.error?.errors || {};
          const parts: string[] = [];
          for (const k of Object.keys(errors)) {
            const msgs = Array.isArray(errors[k]) ? errors[k] : [errors[k]];
            for (const m of msgs) parts.push(`${k}: ${m}`);
          }
          const detail = parts.length ? ` Detalles: ${parts.join(' | ')}` : '';
          this.showAlert((backendMsg || 'Error de validación') + detail, 'warning');
        } else if (status >= 500) {
          this.showAlert(backendMsg || 'Error interno del servidor al obtener resumen', 'error');
        } else {
          this.showAlert(backendMsg || 'Error al obtener resumen', 'error');
        }
        this.loading = false;
      }
    });
  }

  // Botones del card "Opciones de cobro"
  limpiarOpcionesCobro(): void {
    // Ocultar el card y limpiar pagos del lote
    this.showOpciones = false;
    (this.batchForm.get('pagos') as FormArray).clear();
    this.showAlert('Opciones de cobro limpiadas', 'success');
  }

  recargarOpcionesCobro(): void {
    // Reejecuta la consulta con los parámetros del formulario
    const cod = (this.searchForm.get('cod_ceta')?.value || '').toString().trim();
    if (!cod) {
      this.showAlert('Ingrese el Código CETA para consultar', 'warning');
      return;
    }
    this.loadResumen();
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
      // Inicializar formulario del modal con snapshot de la página principal
      const tipo = Number(this.identidadForm.get('tipo_identidad')?.value || 1);
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
    const ci = (this.modalIdentidadForm.get('ci')?.value || '').toString().trim();
    const tipoId = Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 1);
    if (!ci) {
      this.showModalAlert('Ingrese el número de documento para buscar', 'warning');
      return;
    }
    this.loading = true;
    this.cobrosService.buscarRazonSocial(ci, tipoId).subscribe({
      next: (res) => {
        const data = res?.data || null;
        if (data) {
          // Encontrado: si el tipo difiere, ajustar automáticamente el selector del modal
          const tipoEncontrado = Number(data.id_tipo_doc_identidad || tipoId);
          if (tipoEncontrado && tipoEncontrado !== tipoId) {
            this.modalIdentidadForm.patchValue({ tipo_identidad: tipoEncontrado }, { emitEvent: true });
            this.updateModalTipoUI(tipoEncontrado);
            this.showModalAlert('Documento encontrado con un tipo diferente. Se ajustó automáticamente el tipo.', 'warning');
          }
          // Autocompletar y bloquear edición
          this.modalIdentidadForm.patchValue({
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
    const ci = (this.modalIdentidadForm.get('ci')?.value || '').toString().trim();
    const tipoId = Number(this.modalIdentidadForm.get('tipo_identidad')?.value || 1);
    const razon = (this.modalIdentidadForm.get('razon_social')?.value || '').toString().trim();
    const complemento = (this.modalIdentidadForm.get('complemento_ci')?.value || '').toString().trim() || null;
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
          // Reflejar cambios en la página principal SOLO al guardar exitosamente
          this.identidadForm.patchValue({
            tipo_identidad: tipoId,
            ci: ci,
            complemento_habilitado: !!this.modalIdentidadForm.get('complemento_habilitado')?.value,
            complemento_ci: complemento || '',
            razon_social: razon || (res?.data?.razon_social || '')
          }, { emitEvent: false });
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

  // Ajusta labels/placeholders y complemento por tipo en el modal
  private updateModalTipoUI(tipoId: number): void {
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
    // Resetear y deshabilitar complemento cuando no aplique (en el modal)
    if (!this.showComplemento) {
      this.modalIdentidadForm.patchValue({ complemento_habilitado: false, complemento_ci: '' }, { emitEvent: false });
      this.modalIdentidadForm.get('complemento_ci')?.disable({ emitEvent: false });
    }
  }

  private showModalAlert(message: string, type: 'success' | 'error' | 'warning'): void {
    this.modalAlertMessage = message;
    this.modalAlertType = type;
    setTimeout(() => (this.modalAlertMessage = ''), 4000);
  }

  // ================= Mensualidades UI/Logic =================
  openMensualidadModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    const nroCuotas = Number(this.resumen?.totales?.nro_cuotas || 0);
    const pagadas = Number(this.resumen?.cobros?.mensualidad?.count || 0);
    this.mensualidadesPendientes = Math.max(0, nroCuotas - pagadas);
    this.mensualidadPU = Number(this.resumen?.totales?.pu_mensual || 0);
    const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
    this.mensualidadModalForm.patchValue({
      metodo_pago: defaultMetodo,
      cantidad: this.mensualidadesPendientes > 0 ? 1 : 0,
      costo_total: this.mensualidadPU
    }, { emitEvent: false });
    this.recalcMensualidadTotal();
    this.modalTipo = 'mensualidad';
    const modalEl = document.getElementById('mensualidadModal');
    if (modalEl && (window as any).bootstrap?.Modal) {
      const modal = new (window as any).bootstrap.Modal(modalEl);
      modal.show();
    }
  }

  openRezagadoModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','OTRO'])) return;
    this.modalTipo = 'rezagado';
    const modalEl = document.getElementById('mensualidadModal');
    if (modalEl && (window as any).bootstrap?.Modal) {
      const modal = new (window as any).bootstrap.Modal(modalEl);
      modal.show();
    }
  }

  openRecuperacionModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','OTRO'])) return;
    this.modalTipo = 'recuperacion';
    const modalEl = document.getElementById('mensualidadModal');
    if (modalEl && (window as any).bootstrap?.Modal) {
      const modal = new (window as any).bootstrap.Modal(modalEl);
      modal.show();
    }
  }

  private recalcMensualidadTotal(): void {
    const cantidad = Number(this.mensualidadModalForm.get('cantidad')?.value || 0);
    const total = Math.max(0, cantidad) * Number(this.mensualidadPU || 0);
    this.mensualidadModalForm.get('costo_total')?.setValue(total, { emitEvent: false });
  }

  getNextMensualidadNro(): number {
    let maxNro = 0;
    const itemsExistentes = (this.resumen?.cobros?.mensualidad?.items || []) as any[];
    for (const it of itemsExistentes) {
      const n = Number(it?.nro_cobro || 0);
      if (n > maxNro) maxNro = n;
    }
    for (const ctrl of (this.pagos.controls || [])) {
      const n = Number(ctrl.get('nro_cobro')?.value || 0);
      if (n > maxNro) maxNro = n;
    }
    return maxNro + 1;
  }

  onAddPagosFromModal(payload: any): void {
    const hoy = new Date().toISOString().slice(0, 10);
    const pagos = Array.isArray(payload) ? payload : (payload?.pagos || []);
    const headerPatch = Array.isArray(payload) ? null : (payload?.cabecera || null);
    for (const p of pagos || []) {
      this.pagos.push(this.fb.group({
        nro_cobro: [p.nro_cobro, Validators.required],
        id_cuota: [p.id_cuota ?? null],
        id_item: [p.id_item ?? null],
        monto: [p.monto, [Validators.required, Validators.min(0)]],
        fecha_cobro: [p.fecha_cobro || hoy, Validators.required],
        observaciones: [p.observaciones || ''],
        // opcionales que acepta el backend
        descuento: [p.descuento ?? null],
        nro_factura: [p.nro_factura ?? null],
        nro_recibo: [p.nro_recibo ?? null],
        pu_mensualidad: [p.pu_mensualidad ?? 0],
        order: [p.order ?? 0]
      }));
    }
    // Aplicar cabecera si el modal la envió (p.e. id_cuentas_bancarias para TARJETA)
    if (headerPatch && typeof headerPatch === 'object') {
      (this.batchForm.get('cabecera') as FormGroup).patchValue(headerPatch, { emitEvent: false });
    }
    this.showAlert('Pago(s) añadidos al lote', 'success');
  }

  submitBatch(): void {
    if (!this.batchForm.valid || this.pagos.length === 0) {
      this.showAlert('Complete los datos y agregue al menos un pago', 'warning');
      return;
    }
    this.loading = true;
    const { cabecera } = this.batchForm.value as any;
    // Mapear pagos para enviar solo con 'monto' calculado y fallbacks de nro/fecha
    const hoy = new Date().toISOString().slice(0, 10);
    let next = this.getNextMensualidadNro();
    const pagos = (this.pagos.controls || []).map((ctrl, idx) => {
      const raw = (ctrl as FormGroup).getRawValue() as any;
      const subtotal = this.calcRowSubtotal(idx);
      const nro = raw.nro_cobro || (next++);
      const fecha = raw.fecha_cobro || hoy;
      return { ...raw, nro_cobro: nro, fecha_cobro: fecha, monto: subtotal };
    });
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

  // ====== Totales estilo sistema antiguo ======
  calcRowSubtotal(i: number): number {
    const g = this.pagos.at(i) as FormGroup;
    if (!g) return 0;
    const cant = Number(g.get('cantidad')?.value || 0);
    const pu = Number(g.get('pu_mensualidad')?.value || 0);
    const desc = Number(g.get('descuento')?.value || 0);
    const sub = cant * pu - (isNaN(desc) ? 0 : desc);
    return sub > 0 ? sub : 0;
  }

  get totalSubtotal(): number {
    let total = 0;
    for (let i = 0; i < this.pagos.length; i++) total += this.calcRowSubtotal(i);
    return total;
  }

  get totalCobro(): number {
    // Por ahora, igual a la suma de subtotales
    return this.totalSubtotal;
  }

	private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => (this.alertMessage = ''), 4000);
	}
}
