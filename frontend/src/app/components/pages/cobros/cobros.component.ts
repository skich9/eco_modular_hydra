import { Component, OnInit, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { MensualidadModalComponent } from './mensualidad-modal/mensualidad-modal.component';
import { RezagadoModalComponent } from './rezagado-modal/rezagado-modal.component';
import { RecuperacionModalComponent } from './recuperacion-modal/recuperacion-modal.component';
import { ItemsModalComponent } from './items-modal/items-modal.component';
import { environment } from '../../../../environments/environment';

@Component({
  selector: 'app-cobros-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, MensualidadModalComponent, ItemsModalComponent, RezagadoModalComponent, RecuperacionModalComponent],
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
  modalTipo: 'mensualidad' | 'rezagado' | 'recuperacion' | 'arrastre' = 'mensualidad';

  // Datos
  resumen: any = null;
  gestiones: any[] = [];
  formasCobro: any[] = [];
  sinDocsIdentidad: Array<{ codigo: number; descripcion: string }> = [];
  pensums: any[] = [];
  cuentasBancarias: any[] = [];
  // Visibilidad del card de Opciones de cobro
  showOpciones = false;
  // Bloqueo de cuota para pagos parciales combinados (EFECTIVO + TARJETA, etc.)
  private lockedMensualidadCuota: number | null = null;
  // Lista filtrada para el modal según selección en cabecera
  modalFormasCobro: any[] = [];
  // Costo de Rezagado (desde costo_semestral)
  rezagadoCosto: number | null = null;

  // Ref del modal de items
  @ViewChild('itemsDlg') itemsDlg?: ItemsModalComponent;
  // Ref del modal de rezagado
  @ViewChild(RezagadoModalComponent) rezagadoDlg?: RezagadoModalComponent;
  // Ref del modal de recuperación
  @ViewChild(RecuperacionModalComponent) recuperacionDlg?: RecuperacionModalComponent;

  // Modal de éxito (registro realizado)
  successSummary: {
    cod_ceta?: string;
    estudiante?: string;
    carrera?: string;
    pensum?: string;
    gestion?: string;
    rows: Array<{ cant: number; detalle: string; pu: number; descuento: number; subtotal: number; obs?: string }>;
    total?: number;
    docs?: Array<{ anio: number; nro_recibo?: number }>
  } | null = null;

  constructor(
    private fb: FormBuilder,
    private cobrosService: CobrosService,
    private auth: AuthService
  ) {
    this.searchForm = this.fb.group({
      cod_ceta: ['', Validators.required],
      gestion: ['']
    });

    this.batchForm = this.fb.group({
      cabecera: this.fb.group({
        cod_ceta: ['', Validators.required],
        cod_pensum: [''],
        tipo_inscripcion: [''],
        gestion: [''],
        // UI value (como SGA): codigo SIN del método
        codigo_sin: [''],
        // Valor requerido para backend: id interno
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

  private updateRezagadoCosto(): void {
    try {
      const pensum = (this.batchForm.get('cabecera.cod_pensum') as any)?.value || this.resumen?.inscripcion?.cod_pensum || '';
      const gestion = (this.batchForm.get('cabecera.gestion') as any)?.value || this.resumen?.gestion || this.resumen?.inscripcion?.gestion || '';
      if (!pensum) { this.rezagadoCosto = null; return; }
      this.cobrosService.getCostoSemestralByPensum(pensum, gestion).subscribe({
        next: (res) => {
          if (!res?.success) { this.rezagadoCosto = null; return; }
          const rows = Array.isArray(res.data) ? res.data : [];
          // Inferir turno y semestre desde resumen si existen
          const turnoInferido = (() => {
            const t1 = (this.identidadForm.get('turno') as any)?.value || this.resumen?.inscripcion?.turno || this.resumen?.estudiante?.turno || '';
            let t = (t1 || '').toString().trim().toUpperCase();
            if (!t) {
              const codCurso = (this.resumen?.inscripcion?.cod_curso || this.resumen?.inscripciones?.[0]?.cod_curso || '').toString().trim().toUpperCase();
              if (codCurso) {
                const last = codCurso.slice(-1);
                if (last === 'M') t = 'MANANA';
                else if (last === 'T') t = 'TARDE';
                else if (last === 'N') t = 'NOCHE';
              }
            }
            // Mapear abreviaturas u otros posibles valores
            if (t === 'M') t = 'MANANA';
            if (t === 'T') t = 'TARDE';
            if (t === 'N') t = 'NOCHE';
            // Normalizar acentos
            t = t.normalize('NFD').replace(/\p{Diacritic}/gu, '');
            return t;
          })();
          const semestre = Number(this.resumen?.inscripcion?.semestre ?? this.resumen?.estudiante?.semestre ?? 0) || 0;
          // Filtrar por tipo_costo = 'Rezagado'
          const candidatos = rows.filter((r: any) => (r?.tipo_costo || '').toString().toUpperCase() === 'REZAGADO');
          // Preferencia de coincidencia: gestion -> turno -> semestre
          const byGestion = candidatos.filter((r: any) => !gestion || `${r?.gestion}` === `${gestion}`);
          const byTurno = (byGestion.length ? byGestion : candidatos).filter((r: any) => {
            if (!turnoInferido) return true;
            const rt = (r?.turno || '').toString().trim().toUpperCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
            // Aceptar coincidencia exacta MANANA/TARDE/NOCHE y también abreviaturas
            if (rt === turnoInferido) return true;
            if (turnoInferido === 'MANANA' && (rt === 'M')) return true;
            if (turnoInferido === 'TARDE' && (rt === 'T')) return true;
            if (turnoInferido === 'NOCHE' && (rt === 'N')) return true;
            return false;
          });
          const bySemestre = (byTurno.length ? byTurno : (byGestion.length ? byGestion : candidatos)).filter((r: any) => !semestre || Number(r?.semestre || 0) === Number(semestre));
          const pick = (bySemestre[0] || byTurno[0] || byGestion[0] || candidatos[0] || null);
          this.rezagadoCosto = pick ? Number(pick?.monto_semestre || 0) : null;
        },
        error: () => { this.rezagadoCosto = null; }
      });
    } catch { this.rezagadoCosto = null; }
  }

  openMaterialAcademicoModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    // Permitir todos los métodos disponibles en el catálogo
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    // Recalcular lista filtrada para el modal según selección actual
    this.computeModalFormasFromSelection();
    // Abrir modal hijo
    try { this.itemsDlg?.open(); } catch {}
  }

  onAddItem(evt: any): void {
    const hoy = new Date().toISOString().slice(0, 10);
    const pagos = Array.isArray(evt) ? evt : (evt?.pagos || []);
    const headerPatch = Array.isArray(evt) ? null : (evt?.cabecera || null);
    if (!Array.isArray(pagos) || pagos.length === 0) {
      this.showAlert('No se pudo agregar el item (payload vacío)', 'warning');
      return;
    }
    pagos.forEach((p: any) => {
      const detalle = (p.detalle || '').toString();
      const pu = Number(p.pu_mensualidad || 0);
      const cant = Math.max(1, Number(p.cantidad || 1));
      const desc = Number(p.descuento || 0) || 0;
      const subtotal = Math.max(0, cant * pu - desc);
      const medioDoc: 'C' | 'M' | '' = (p.medio_doc === 'M' || p.computarizada === 'MANUAL') ? 'M' : (p.medio_doc === 'C' || p.computarizada === 'COMPUTARIZADA') ? 'C' : '' as any;
      const tipoDoc: 'F' | 'R' | '' = (p.tipo_documento === 'F' || p.comprobante === 'FACTURA') ? 'F' : (p.tipo_documento === 'R' || p.comprobante === 'RECIBO') ? 'R' : '' as any;
      const nro = Number(p?.nro_cobro || 0) || this.getNextCobroNro();
      this.pagos.push(this.fb.group({
        // Backend-required/known fields
        id_forma_cobro: [p.id_forma_cobro ?? (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value ?? null],
        nro_cobro: [nro, Validators.required],
        id_cuota: [null],
        id_asignacion_costo: [null],
        id_item: [p.id_item ?? null],
        monto: [subtotal, [Validators.required, Validators.min(0)]],
        fecha_cobro: [p.fecha_cobro || hoy, Validators.required],
        observaciones: [(p.observaciones || '').toString().trim()],
        // opcionales que acepta el backend
        descuento: [desc || null],
        nro_factura: [p.nro_factura ?? null],
        nro_recibo: [p.nro_recibo ?? null],
        tipo_documento: [tipoDoc],
        medio_doc: [medioDoc],
        pu_mensualidad: [pu],
        order: [p.order ?? 0],
        // Datos bancarios/tarjeta para nota_bancaria
        id_cuentas_bancarias: [p.id_cuentas_bancarias ?? null],
        banco_origen: [p.banco_origen ?? null],
        fecha_deposito: [p.fecha_deposito ?? null],
        nro_deposito: [p.nro_deposito ?? null],
        tarjeta_first4: [p.tarjeta_first4 ?? null],
        tarjeta_last4: [p.tarjeta_last4 ?? null],
        // Campos UI
        cantidad: [cant, [Validators.required, Validators.min(1)]],
        detalle: [detalle],
        m_marca: [false],
        d_marca: [false],
        es_parcial: [false]
      }));
    });
    // Aplicar cabecera si el modal la envió (p.e. id_cuentas_bancarias para TARJETA/DEPÓSITO/etc.)
    if (headerPatch && typeof headerPatch === 'object') {
      (this.batchForm.get('cabecera') as FormGroup).patchValue(headerPatch, { emitEvent: false });
    }
    this.showAlert('Item añadido al lote', 'success');
  }

  openArrastreModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    const arr = this.resumen?.arrastre;
    if (!arr?.has) {
      this.showAlert('No hay inscripción de arrastre en la gestión seleccionada', 'warning');
      return;
    }
    const pend = Number(arr?.pending_count || 0);
    const next = arr?.next_cuota;
    if (!next || pend <= 0) {
      this.showAlert('No hay cuotas de arrastre pendientes', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    // Definir tipo antes de propagar inputs al modal
    this.modalTipo = 'arrastre';
    // Configurar PU y pendientes para arrastre
    this.mensualidadPU = Number(next?.monto || 0);
    this.mensualidadesPendientes = Math.max(0, Number(arr?.pending_count || 0));
    // Recalcular lista filtrada y escoger default coherente
    this.computeModalFormasFromSelection();
    const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
    const firstAllowed = (this.modalFormasCobro[0]?.id_forma_cobro || '').toString();
    this.mensualidadModalForm.patchValue({
      metodo_pago: firstAllowed || defaultMetodo,
      cantidad: 1,
      costo_total: this.mensualidadPU
    }, { emitEvent: false });
    // No es necesario recalcular el form auxiliar del padre
    const modalEl = document.getElementById('mensualidadModal');
    if (modalEl && (window as any).bootstrap?.Modal) {
      const modal = new (window as any).bootstrap.Modal(modalEl);
      modal.show();
    }
  }


  private buildSuccessModalHtml(): string {
    const s = this.successSummary || { rows: [] } as any;
    const rowsHtml = (s.rows || []).map((r: any) => `
      <tr>
        <td class="text-center">${r.cant ?? ''}</td>
        <td>
          <div class="small">${(r.detalle ?? '').toString().replace(/</g,'&lt;')}</div>
          ${r.obs ? `<div class="text-muted small">${(r.obs ?? '').toString().replace(/</g,'&lt;')}</div>` : ''}
        </td>
        <td class="text-end">${Number(r.pu||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        <td class="text-end">${Number(r.descuento||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</td>
        <td class="text-end">${Number(r.subtotal||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</td>
      </tr>`).join('');
    const totalFmt = Number(s.total||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
    const pensumStr = s.pensum ? ` (${s.pensum})` : '';
    return `
      <div class="modal-dialog modal-lg">
        <div class="modal-content success-modal">
          <div class="modal-header">
            <h5 class="modal-title">Registro realizado</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="btnSuccessCloseX"></button>
          </div>
          <div class="modal-body">
            <div class="border rounded overflow-hidden mb-3">
              <div class="panel-header">Datos del Estudiante:</div>
              <div class="panel-body">
                <div class="row g-3">
                  <div class="col-9">
                    <div><strong>Código:</strong> ${s.cod_ceta ?? ''}</div>
                    <div><strong>Estudiante:</strong> ${(s.estudiante ?? '').toString().replace(/</g,'&lt;')}</div>
                    <div><strong>Carrera:</strong> ${(s.carrera ?? '').toString().replace(/</g,'&lt;')}${pensumStr}</div>
                    <div><strong>Gestión:</strong> ${s.gestion ?? ''}</div>
                  </div>
                  <div class="col-3 d-flex align-items-center justify-content-center">
                    <i class="bi bi-person-circle" style="font-size:48px; opacity:.5"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="border rounded overflow-hidden">
              <div class="panel-header">Se realizó el registro de los siguientes datos</div>
              <div class="panel-body">
                <div class="table-responsive">
                  <table class="table table-sm table-bordered align-middle mb-2">
                    <thead class="table-light">
                      <tr>
                        <th style="width:8%">Cant.</th>
                        <th>Detalle</th>
                        <th style="width:14%">P/u</th>
                        <th style="width:14%">Descuento</th>
                        <th style="width:18%">Sub total</th>
                      </tr>
                    </thead>
                    <tbody>${rowsHtml}</tbody>
                    <tfoot>
                      <tr class="table-success">
                        <th colspan="4" class="text-end">Subtotales:</th>
                        <th class="text-end">${totalFmt}</th>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="btnSuccessPrint"><i class="bi bi-printer me-1"></i> Imprimir</button>
            <button type="button" class="btn btn-danger" id="btnSuccessClose" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>`;
  }

  private buildSuccessSummary(createdItems: any[]): any {
    try {
      const est = this.resumen?.estudiante || {};
      const ins = (this.resumen?.inscripcion || this.resumen?.inscripciones?.[0]) || {};
      const nombre = [est.nombres, est.ap_paterno, est.ap_materno].filter(Boolean).join(' ').trim();
      const carrera: string = (est?.pensum?.carrera?.nombre || '') as any;
      const pensum: string = (ins?.cod_pensum || this.batchForm.get('cabecera.cod_pensum')?.value || '') + '';
      const gestion: string = (this.resumen?.gestion || ins?.gestion || this.batchForm.get('cabecera.gestion')?.value || '') + '';
      const cod_ceta: string = (est?.cod_ceta || this.batchForm.get('cabecera.cod_ceta')?.value || this.searchForm.get('cod_ceta')?.value || '') + '';
      const rows = (this.pagos.controls || []).map((ctrl, idx) => {
        const g = ctrl as FormGroup;
        return {
          cant: Number(g.get('cantidad')?.value || 0),
          detalle: (g.get('detalle')?.value || '').toString(),
          pu: Number(g.get('pu_mensualidad')?.value || 0),
          descuento: Number(g.get('descuento')?.value || 0),
          subtotal: this.calcRowSubtotal(idx),
          obs: (g.get('observaciones')?.value || '').toString()
        };
      });
      const total = rows.reduce((acc, r) => acc + Number(r.subtotal || 0), 0);
      const docs = (createdItems || []).filter((it: any) => (it?.tipo_documento === 'R' && it?.medio_doc === 'C' && it?.nro_recibo)).map((it: any) => {
        const fecha = it?.cobro?.fecha_cobro || new Date().toISOString().slice(0,10);
        const anio = new Date(fecha).getFullYear();
        return { anio, nro_recibo: it?.nro_recibo };
      });
      return { cod_ceta, estudiante: nombre, carrera, pensum, gestion, rows, total, docs };
    } catch {
      return { rows: [] };
    }
  }

  private openSuccessModal(): void {
    // Crear modal dinámicamente si no existe en el template
    let modalEl = document.getElementById('successModal');
    if (!modalEl) {
      modalEl = document.createElement('div');
      modalEl.id = 'successModal';
      modalEl.className = 'modal fade';
      modalEl.setAttribute('tabindex', '-1');
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.innerHTML = this.buildSuccessModalHtml();
      document.body.appendChild(modalEl);
      // Wire buttons after insertion
      setTimeout(() => {
        const btnPrint = document.getElementById('btnSuccessPrint');
        const btnClose = document.getElementById('btnSuccessClose');
        btnPrint?.addEventListener('click', () => this.onSuccessPrint());
        // Refrescar sólo con el botón Cerrar
        btnClose?.addEventListener('click', () => this.onSuccessClose());
        // Auto-clean DOM when closed
        const bs = (window as any).bootstrap;
        if (bs?.Modal) {
          modalEl?.addEventListener('hidden.bs.modal', () => {
            try { modalEl?.remove(); } catch {}
          });
        }
      }, 0);
    } else {
      // Update content if already exists
      modalEl.innerHTML = this.buildSuccessModalHtml();
      const btnPrint = document.getElementById('btnSuccessPrint');
      const btnClose = document.getElementById('btnSuccessClose');
      btnPrint?.addEventListener('click', () => this.onSuccessPrint());
      btnClose?.addEventListener('click', () => this.onSuccessClose());
    }
    const bs = (window as any).bootstrap;
    if (modalEl && bs?.Modal) {
      const modal = new bs.Modal(modalEl, { backdrop: 'static', keyboard: false });
      modal.show();
    }
  }

  onSuccessPrint(): void {
    try {
      const docs = this.successSummary?.docs || [];
      if (docs.length) {
        for (const d of docs) {
          if (d?.nro_recibo && d?.anio) {
            this.cobrosService.downloadReciboPdf(d.anio, d.nro_recibo).subscribe({ next: () => {}, error: () => {} });
          }
        }
        return;
      }
    } catch {}
    try { window.print(); } catch {}
  }

  onSuccessClose(): void {
    // Limpiar todo como si fuera la primera carga
    try {
      (this.batchForm.get('pagos') as FormArray).clear();
      this.batchForm.reset({ cabecera: { id_forma_cobro: '', id_cuentas_bancarias: '' }, pagos: [] });
      this.identidadForm.reset({ nombre_completo: '', tipo_identidad: 1, ci: '', complemento_habilitado: false, complemento_ci: '', razon_social: '', email_habilitado: false, email: '', turno: '' });
      this.modalIdentidadForm.reset({ tipo_identidad: 1, ci: '', complemento_habilitado: false, complemento_ci: '', razon_social: '' });
      this.mensualidadModalForm.reset({ metodo_pago: '', cantidad: 1, costo_total: 0, observaciones: '' });
      this.searchForm.reset({ cod_ceta: '', gestion: '' });
      this.resumen = null;
      this.showOpciones = false;
      this.alertMessage = '';
      this.successSummary = null;
    } catch {}

    // Cerrar modal si hay instancia y recargar para estado inicial real
    const modalEl = document.getElementById('successModal');
    const bs = (window as any).bootstrap;
    if (modalEl && bs?.Modal) {
      const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
      instance.hide();
    }
    try { setTimeout(() => { window.location.reload(); }, 150); } catch {}
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
          // Orden ascendente por codigo_sin y luego por descripcion/nombre
          this.formasCobro = (res.data || []).slice().sort((a: any, b: any) => {
            const ca = Number(a?.codigo_sin ?? 0);
            const cb = Number(b?.codigo_sin ?? 0);
            if (ca !== cb) return ca - cb;
            const la = (a?.descripcion_sin ?? a?.nombre ?? '').toString();
            const lb = (b?.descripcion_sin ?? b?.nombre ?? '').toString();
            return la.localeCompare(lb);
          });
          // Si ya hay un valor y corresponde a EFECTIVO, limpiar error custom
          this.clearSoloEfectivoErrorIfMatches();
          // Sincronizar codigo_sin desde id_forma_cobro si ya hubiese uno seleccionado
          const cab = this.batchForm.get('cabecera') as FormGroup;
          const idSel = (cab.get('id_forma_cobro')?.value ?? '').toString();
          if (idSel) {
            const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === idSel);
            if (match) {
              cab.patchValue({ codigo_sin: match.codigo_sin }, { emitEvent: false });
            }
          }
          // Calcular opciones del modal según selección actual
          this.computeModalFormasFromSelection();
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
    const id = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value;
    return (id === null || id === undefined) ? '' : `${id}`;
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
    const last = this.pagos.length - 1;
    if (i !== last) {
      this.showAlert('Solo puede eliminar la última fila del detalle', 'warning');
      return;
    }
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

          // Datos para Mensualidad:
          // - pendientes: según expectativa del usuario = pending_count + parcial_count
          const nroCuotas = Number(this.resumen?.totales?.nro_cuotas || 0);
          const pagadasFull = Number(((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]).filter?.((a: any) => (a?.estado_pago || '') === 'COBRADO').length || 0);
          const pendFromNext = Number(this.resumen?.mensualidad_next?.pending_count ?? 0) || 0;
          const parcialesCnt = Number(this.resumen?.mensualidad_next?.parcial_count ?? 0) || 0;
          const combined = pendFromNext + parcialesCnt;
          this.mensualidadesPendientes = combined > 0 ? combined : Math.max(0, nroCuotas - pagadasFull);
          // - PU: next_cuota.monto ya es restante si es PARCIAL, o el monto si es PENDIENTE
          const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
          this.mensualidadPU = puNext > 0 ? puNext : Number(this.resumen?.totales?.pu_mensual || 0);
          // Inicializar modal de mensualidades
          const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
          this.mensualidadModalForm.patchValue({
            metodo_pago: defaultMetodo,
            cantidad: this.mensualidadesPendientes > 0 ? 1 : 0,
            costo_total: this.mensualidadPU
          }, { emitEvent: false });
          this.recalcMensualidadTotal();
          // Calcular costo de Rezagado desde costo_semestral
          this.updateRezagadoCosto();
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

  onMetodoPagoChange(ev: any): void {
    const sel = (ev?.target?.value ?? '').toString(); // codigo_sin
    const cab = this.batchForm.get('cabecera') as FormGroup;
    cab.patchValue({ codigo_sin: sel }, { emitEvent: false });
    // Mapear a id_forma_cobro requerido por backend
    let match = (this.formasCobro || []).find((f: any) => `${f?.codigo_sin}` === sel);
    if (!match) match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === sel);
    const idInterno = match ? `${match.id_forma_cobro}` : '';
    cab.patchValue({ id_forma_cobro: idInterno }, { emitEvent: false });
    const idCtrl = cab.get('id_forma_cobro');
    idCtrl?.markAsTouched();
    idCtrl?.updateValueAndValidity({ emitEvent: false });
    // Revalidar y limpiar errores si corresponde
    this.clearSoloEfectivoErrorIfMatches();
    // Recalcular opciones para el modal (filtradas por selección actual)
    this.computeModalFormasFromSelection();
  }

  openKardexModal(): void {
    const modalEl = document.getElementById('kardexModal');
    if (modalEl && (window as any).bootstrap?.Modal) {
      const modal = new (window as any).bootstrap.Modal(modalEl);
      modal.show();
    }
  }

  // ===================== Filtro de formas para el modal =====================
  private normalizeLabel(s: string): string {
    return (s || '').toString().trim().toUpperCase()
      .normalize('NFD').replace(/\p{Diacritic}/gu, '');
  }

  private findBaseForma(name: string): any | null {
    const target = this.normalizeLabel(name);
    // Preferir entradas "base" (sin guiones) por coincidencia de nombre con scoring
    const prioritized: Record<string, string[]> = {
      'EFECTIVO': ['EFECTIVO'],
      'TARJETA': ['TARJETA'],
      'CHEQUE': ['CHEQUE'],
      'DEPOSITO': ['DEPOSITO EN CUENTA', 'DEPOSITO'],
      'TRANSFERENCIA': ['TRANSFERENCIA BANCARIA', 'TRANSFERENCIA'],
      'VALES': ['VALES'],
      'OTRO': ['OTRO', 'OTROS']
    };
    const patterns = prioritized[target] || [target];
    const candidates = (this.formasCobro || []).filter((f: any) => {
      const raw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString();
      const n = this.normalizeLabel(raw);
      const isCombo = n.includes('-');
      if (isCombo) return false;
      // Debe contener alguno de los patrones objetivo
      if (!patterns.some(p => n.includes(this.normalizeLabel(p)))) return false;
      // Excluir entradas no deseadas
      if (target !== 'VALES' && n.includes('VALES')) return false;
      if (target !== 'OTRO' && (n.includes('OTRO') || n.includes('OTROS') || n.includes('PAGO POSTERIOR'))) return false;
      if (target === 'TRANSFERENCIA' && n.includes('SWIFT')) return false;
      return true;
    });
    if (candidates.length) {
      const score = (f: any) => {
        const raw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString();
        const n = this.normalizeLabel(raw);
        let s = 0;
        for (const p of patterns) {
          const np = this.normalizeLabel(p);
          if (n === np) s += 100; // exacto
          if (n.startsWith(np)) s += 50; // empieza con patrón
          if (n.includes(np)) s += 10; // contiene
        }
        // preferir descripciones SIN (cuando existen) sobre nombre genérico
        if (f?.descripcion_sin) s += 10;
        return s;
      };
      candidates.sort((a, b) => score(b) - score(a));
      return candidates[0];
    }
    // Fallback: por id_forma_cobro (solo si realmente es 'OTRO')
    const idMap: Record<string, string[]> = {
      'EFECTIVO': ['E'], 'CHEQUE': ['C'], 'DEPOSITO': ['D'], 'TARJETA': ['T'], 'TRANSFERENCIA': ['TR','X'], 'OTRO': ['O'], 'VALES': ['V']
    } as any;
    const ids = idMap[target] || [];
    const byId = (this.formasCobro || []).find((f: any) => ids.includes((`${f?.id_forma_cobro}`).toUpperCase()));
    return byId || null;
  }

  private computeModalFormasFromSelection(): void {
    try {
      const cab = this.batchForm.get('cabecera') as FormGroup;
      const sel = (cab.get('codigo_sin')?.value ?? '').toString();
      let match = (this.formasCobro || []).find((f: any) => `${f?.codigo_sin}` === sel);
      if (!match) match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === sel);
      if (!match) { this.modalFormasCobro = []; return; }
      const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString();
      const label = this.normalizeLabel(raw);

      // Si NO es combinado (sin guiones ni en-dash/em-dash), usar exactamente la forma seleccionada
      if (!/[\-–—]/.test(label)) {
        this.modalFormasCobro = [match];
        return;
      }

      // Combinado: dividir por separadores y mapear cada fragmento a un token canónico
      const parts = raw.split(/[\-–—]/).map((p: string) => p.trim()).filter(Boolean);
      const toToken = (s: string): string | null => {
        const n = this.normalizeLabel(s);
        if (n.includes('EFECTIVO')) return 'EFECTIVO';
        if (n.includes('TARJETA')) return 'TARJETA';
        if (n.includes('CHEQUE')) return 'CHEQUE';
        if (n.includes('DEPOSITO')) return 'DEPOSITO';
        if (n.includes('TRANSFERENCIA')) return 'TRANSFERENCIA';
        if (n.includes('SWIFT')) return 'OTRO';
        if (n.includes('VALES')) return 'VALES';
        if (n.includes('GIFT')) return 'OTRO';
        if (n.includes('CANAL')) return 'OTRO';
        if (n.includes('OTRO')) return 'OTRO';
        // Fallback: cualquier no reconocido se trata como OTRO para permitir combinaciones EFECTIVO-<otro>
        return 'OTRO';
      };
      // Construir lista filtrada a partir de las partes exactas primero
      const out: any[] = [];
      const seenCodes = new Set<string>();
      const norm = (s: string) => this.normalizeLabel(s);
      for (const part of parts) {
        const np = norm(part);
        // 1) Match exacto por descripcion_sin
        let f = (this.formasCobro || []).find((x: any) => norm(x?.descripcion_sin || x?.nombre || '') === np);
        // 2) Fallback por token
        if (!f) {
          const t = toToken(part);
          if (t) f = this.findBaseForma(t);
        }
        const codeKey = f ? `${f.codigo_sin}` : '';
        if (f && !seenCodes.has(codeKey)) { out.push(f); seenCodes.add(codeKey); }
      }
      this.modalFormasCobro = out;
    } catch {
      this.modalFormasCobro = [];
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
    const pendFromNext = Number(this.resumen?.mensualidad_next?.pending_count ?? 0) || 0;
    const parcialesCnt = Number(this.resumen?.mensualidad_next?.parcial_count ?? 0) || 0;
    // Mostrar suma pedida por usuario (pendientes + parciales)
    this.mensualidadesPendientes = Math.max(0, pendFromNext + parcialesCnt);
    const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
    this.mensualidadPU = puNext > 0 ? puNext : Number(this.resumen?.totales?.pu_mensual || 0);
    const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
    // Recalcular lista filtrada y escoger default coherente
    this.computeModalFormasFromSelection();
    const firstAllowed = (this.modalFormasCobro[0]?.id_forma_cobro || '').toString();
    this.mensualidadModalForm.patchValue({
      metodo_pago: firstAllowed || defaultMetodo,
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
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    // Calcular lista de métodos permitidos en el modal según selección actual
    this.computeModalFormasFromSelection();
    this.modalTipo = 'rezagado';
    try { this.rezagadoDlg?.open(); } catch {}
  }


  openRecuperacionModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    try { this.recuperacionDlg?.open(); } catch {}
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

  // Considera mensualidades e items para evitar duplicados globales
  private getMaxCobroNroInResumen(): number {
    let max = 0;
    const mens = (this.resumen?.cobros?.mensualidad?.items || []) as any[];
    for (const it of mens) {
      const n = Number(it?.nro_cobro || 0);
      if (n > max) max = n;
    }
    const otros = (this.resumen?.cobros?.items?.items || []) as any[];
    for (const it of otros) {
      const n = Number(it?.nro_cobro || 0);
      if (n > max) max = n;
    }
    for (const ctrl of (this.pagos.controls || [])) {
      const n = Number((ctrl as FormGroup).get('nro_cobro')?.value || 0);
      if (n > max) max = n;
    }
    return max;
  }

  getNextCobroNro(): number {
    return this.getMaxCobroNroInResumen() + 1;
  }

  private computeMaxFromResumenData(data: any): number {
    try {
      let max = 0;
      const mens = (data?.cobros?.mensualidad?.items || []) as any[];
      for (const it of mens) {
        const n = Number(it?.nro_cobro || 0);
        if (n > max) max = n;
      }
      const otros = (data?.cobros?.items?.items || []) as any[];
      for (const it of otros) {
        const n = Number(it?.nro_cobro || 0);
        if (n > max) max = n;
      }
      return max;
    } catch { return 0; }
  }

  // Devuelve cuántas cuotas de mensualidad ya están agregadas en el FormArray (no enviadas aún)
  private countMensualidadCuotasInForm(): number {
    let count = 0;
    for (const ctrl of (this.pagos.controls || [])) {
      const det = (ctrl.get('detalle')?.value || '').toString();
      const detUpper = det.toUpperCase();
      // Contar solo cuotas completas (ignorar filas con '(Parcial)')
      if (/^\s*Mensualidad\s*-\s*Cuota\s+\d+\s*$/i.test(det) && !detUpper.includes('(PARCIAL)')) count++;
    }
    return count;
  }

  // Obtiene el mayor número de cuota ya agregado en el FormArray de pagos
  private getMaxMensualidadCuotaInForm(): number {
    let max = 0;
    for (const ctrl of (this.pagos.controls || [])) {
      const det = (ctrl.get('detalle')?.value || '').toString();
      const m = det.match(/Cuota\s+(\d+)/i);
      const n = m ? Number(m[1]) : 0;
      if (n > max) max = n;
    }
    return max;
  }

  // Obtiene el último número de cuota parcial agregado en el FormArray (si existe)
  private getLastMensualidadParcialCuotaInForm(): number | null {
    for (let i = this.pagos.length - 1; i >= 0; i--) {
      const ctrl = this.pagos.at(i) as FormGroup;
      const det = (ctrl.get('detalle')?.value || '').toString();
      if (/(Mensualidad)\s*-\s*Cuota\s+\d+\s*\(Parcial\)/i.test(det)) {
        const m = det.match(/Cuota\s+(\d+)/i);
        const n = m ? Number(m[1]) : null;
        if (n && isFinite(n)) return n;
      }
    }
    return null;
  }

  // Calcula desde qué número de cuota debe comenzar el siguiente agregado de mensualidades
  private getNextMensualidadStartCuota(): number {
    const backendNext = Number(this.resumen?.mensualidad_next?.next_cuota?.numero_cuota || 1);
    const inFormMax = this.getMaxMensualidadCuotaInForm();
    // Empezar desde el mayor observado (backend o en-form) + 1
    return Math.max(backendNext, inFormMax + 1);
  }

  onAddPagosFromModal(payload: any): void {
    const hoy = new Date().toISOString().slice(0, 10);
    const pagos = Array.isArray(payload) ? payload : (payload?.pagos || []);
    const headerPatch = Array.isArray(payload) ? null : (payload?.cabecera || null);
    const isMensualidad = this.modalTipo === 'mensualidad';
    const isArrastre = this.modalTipo === 'arrastre';
    const startCuota = this.getNextMensualidadStartCuota();
    pagos.forEach((p: any, idx: number) => {
      const resolveFormaId = (val: any): any => {
        const s = (val === null || val === undefined) ? '' : `${val}`;
        if (!s) return null;
        const byId = this.formasCobro.find((f: any) => `${f?.id_forma_cobro}` === s);
        if (byId) return byId.id_forma_cobro;
        const byCodigo = this.formasCobro.find((f: any) => `${f?.codigo_sin}` === s);
        if (byCodigo) return byCodigo.id_forma_cobro;
        return s; // devolver lo que venga
      };
      // Regla: si hay bloqueo de cuota por parcial, usarlo; sino usa p.numero_cuota o cálculo incremental
      let numeroCuota: number | null = null;
      if (isMensualidad) {
        const fromPayload = Number(p?.numero_cuota || 0) || null;
        if (this.lockedMensualidadCuota) {
          numeroCuota = this.lockedMensualidadCuota;
        } else if (fromPayload) {
          numeroCuota = fromPayload;
        } else {
          // Si ya existe una fila parcial, fijar bloqueo a esa cuota para pagos combinados
          const lastParcial = this.getLastMensualidadParcialCuotaInForm();
          if (lastParcial) {
            this.lockedMensualidadCuota = lastParcial;
            numeroCuota = lastParcial;
          } else {
            numeroCuota = startCuota + idx;
          }
        }
      } else if (isArrastre) {
        // Para arrastre, respetar el número de cuota provisto por el modal/payload
        const fromPayload = Number(p?.numero_cuota || 0) || null;
        numeroCuota = fromPayload;
      }
      const esParcial = !!p.pago_parcial;
      const baseDetalle = isMensualidad
        ? `Mensualidad - Cuota ${numeroCuota}`
        : (isArrastre
            ? `Mensualidad (Arrastre) - Cuota ${numeroCuota ?? ''}`.trim()
            : (p.detalle || ''));
      const detalle = esParcial ? `${baseDetalle} (Parcial)` : baseDetalle;
      const pu = Number(p.pu_mensualidad ?? this.mensualidadPU ?? 0);
      const cant = 1;
      const desc = p.descuento ?? 0;
      // Si es parcial y viene un monto explícito, usarlo respetando un tope máximo de PU
      const montoBase = esParcial ? Number(p.monto || 0) : (cant * pu);
      const monto = Math.max(0, montoBase - (isNaN(desc) ? 0 : Number(desc)));
      const obsStr = ((p.observaciones || '') + '').trim();
      // Mapear medio/doc para UI y envío
      const medioDoc: 'C' | 'M' | '' = (p.medio_doc === 'M' || p.computarizada === 'MANUAL') ? 'M' : (p.medio_doc === 'C' || p.computarizada === 'COMPUTARIZADA') ? 'C' : '' as any;
      const tipoDoc: 'F' | 'R' | '' = (p.tipo_documento === 'F' || p.comprobante === 'FACTURA') ? 'F' : (p.tipo_documento === 'R' || p.comprobante === 'RECIBO') ? 'R' : '' as any;
      this.pagos.push(this.fb.group({
        // Backend-required/known fields
        id_forma_cobro: [resolveFormaId(p.id_forma_cobro) ?? null],
        nro_cobro: [p.nro_cobro, Validators.required],
        id_cuota: [p.id_cuota ?? null],
        id_asignacion_costo: [p.id_asignacion_costo ?? null],
        id_item: [p.id_item ?? null],
        monto: [monto, [Validators.required, Validators.min(0)]],
        fecha_cobro: [p.fecha_cobro || hoy, Validators.required],
        observaciones: [obsStr],
        // opcionales que acepta el backend
        descuento: [desc ?? null],
        nro_factura: [p.nro_factura ?? null],
        nro_recibo: [p.nro_recibo ?? null],
        tipo_documento: [tipoDoc],
        medio_doc: [medioDoc],
        pu_mensualidad: [esParcial ? monto : pu],
        order: [p.order ?? 0],
        // Datos bancarios/tarjeta para nota_bancaria
        id_cuentas_bancarias: [p.id_cuentas_bancarias ?? null],
        banco_origen: [p.banco_origen ?? null],
        fecha_deposito: [p.fecha_deposito ?? null],
        nro_deposito: [p.nro_deposito ?? null],
        tarjeta_first4: [p.tarjeta_first4 ?? null],
        tarjeta_last4: [p.tarjeta_last4 ?? null],
        // Campos UI para mostrar en la tabla como labels
        cantidad: [cant, [Validators.required, Validators.min(1)]],
        detalle: [detalle],
        m_marca: [false],
        d_marca: [false],
        es_parcial: [esParcial]
      }));

      // Actualizar bloqueo: si es parcial, bloquear esa cuota; si no es parcial, liberar bloqueo
      if (isMensualidad) {
        if (esParcial && numeroCuota) {
          this.lockedMensualidadCuota = numeroCuota;
        } else if (!esParcial) {
          this.lockedMensualidadCuota = null;
        }
      }
    });
    // Aplicar cabecera si el modal la envió (p.e. id_cuentas_bancarias para TARJETA)
    if (headerPatch && typeof headerPatch === 'object') {
      (this.batchForm.get('cabecera') as FormGroup).patchValue(headerPatch, { emitEvent: false });
    }
    this.showAlert('Pago(s) añadidos al lote', 'success');
  }

  submitBatch(): void {
    if (this.loading) {
      console.warn('[Cobros] submitBatch() ignored because loading=true');
      return;
    }
    console.log('[Cobros] submitBatch() called', {
      valid: this.batchForm.valid,
      pagosLen: this.pagos.length,
      cabecera: (this.batchForm.get('cabecera') as FormGroup)?.getRawValue?.() || null
    });
    const cab = this.batchForm.get('cabecera') as FormGroup;
    // 1) cod_ceta: desde resumen o searchForm si falta
    try {
      const currentCod = cab?.get('cod_ceta')?.value;
      if (!currentCod) {
        const codFromResumen = this.resumen?.estudiante?.cod_ceta || '';
        const codFromSearch = (this.searchForm.get('cod_ceta')?.value || '').toString().trim();
        const codFinal = codFromResumen || codFromSearch;
        if (codFinal) cab.patchValue({ cod_ceta: codFinal }, { emitEvent: false });
      }
    } catch {}
    // 1.1) cod_pensum / tipo_inscripcion / gestion desde resumen.inscripcion si faltan
    try {
      const ins = (this.resumen as any)?.inscripcion || (this.resumen as any)?.inscripciones?.[0] || null;
      const patch: any = {};
      if (!cab?.get('cod_pensum')?.value && ins?.cod_pensum) patch.cod_pensum = String(ins.cod_pensum);
      if (!cab?.get('tipo_inscripcion')?.value && ins?.tipo_inscripcion) patch.tipo_inscripcion = String(ins.tipo_inscripcion);
      if (!cab?.get('gestion')?.value && (this.resumen as any)?.gestion) patch.gestion = String((this.resumen as any).gestion);
      if (Object.keys(patch).length) cab.patchValue(patch, { emitEvent: false });
    } catch {}
    // 2) id_forma_cobro: tomar del modal si cabecera está vacío
    try {
      const currentForma = cab?.get('id_forma_cobro')?.value;
      if (!currentForma) {
        const metodo = this.mensualidadModalForm.get('metodo_pago')?.value;
        if (metodo) cab.patchValue({ id_forma_cobro: String(metodo) }, { emitEvent: false });
      }
    } catch {}
    // 3) id_usuario: desde AuthService o localStorage current_user
    try {
      const currentUser = this.auth?.getCurrentUser?.();
      if (currentUser?.id_usuario && !cab?.get('id_usuario')?.value) {
        cab.patchValue({ id_usuario: currentUser.id_usuario }, { emitEvent: false });
      } else if (!cab?.get('id_usuario')?.value && typeof localStorage !== 'undefined') {
        const raw = localStorage.getItem('current_user');
        if (raw) {
          const parsed = JSON.parse(raw);
          if (parsed?.id_usuario) cab.patchValue({ id_usuario: parsed.id_usuario }, { emitEvent: false });
        }
      }
    } catch {}
    // Pre-completar fecha/monto y ASIGNAR nro_cobro único en cliente (hasta que backend lo haga atómico)
    try {
      const hoy = new Date().toISOString().slice(0, 10);
      let next = this.getNextCobroNro();
      (this.pagos.controls || []).forEach((ctrl, idx) => {
        const fg = ctrl as FormGroup;
        const raw: any = fg.getRawValue();
        const subtotal = this.calcRowSubtotal(idx);
        const fecha = raw?.fecha_cobro || hoy;
        const nro = raw?.nro_cobro ? Number(raw.nro_cobro) : (next++);
        fg.patchValue({ fecha_cobro: fecha, monto: subtotal, nro_cobro: nro }, { emitEvent: false });
      });
      this.batchForm.updateValueAndValidity({ onlySelf: false, emitEvent: false });
    } catch {}
    // Forzar visualización de errores de validación en el formulario
    try { this.batchForm.markAllAsTouched(); } catch {}
    if (!this.batchForm.valid || this.pagos.length === 0) {
      console.warn('[Cobros] submitBatch() invalid form or empty pagos');
      this.showAlert('Complete los datos y agregue al menos un pago', 'warning');
      return;
    }
    this.loading = true;
    const { cabecera } = this.batchForm.value as any;
    // Mapear pagos para enviar solo con 'monto' calculado y fallbacks de nro/fecha
    const hoy = new Date().toISOString().slice(0, 10);
    const pagos = (this.pagos.controls || []).map((ctrl, idx) => {
      const raw = (ctrl as FormGroup).getRawValue() as any;
      const subtotal = this.calcRowSubtotal(idx);
      const fecha = raw.fecha_cobro || hoy;
      const item: any = { ...raw, fecha_cobro: fecha, monto: subtotal, nro_cobro: Number(raw?.nro_cobro || 0) };
      if (!item.nro_cobro || item.nro_cobro <= 0) {
        item.nro_cobro = this.getNextCobroNro();
      }
      return item;
    });
    const payload = {
      ...cabecera,
      id_forma_cobro: (cabecera?.id_forma_cobro !== undefined && cabecera?.id_forma_cobro !== null)
        ? String(cabecera.id_forma_cobro)
        : cabecera?.id_forma_cobro,
      pagos,
      cliente: {
        tipo_identidad: Number(this.identidadForm.get('tipo_identidad')?.value || 0),
        numero: (this.identidadForm.get('ci')?.value || '').toString(),
        razon_social: (this.identidadForm.get('razon_social')?.value || '').toString()
      }
    } as any;
    this.cobrosService.batchStore(payload).subscribe({
      next: (res) => {
        if (res.success) {
          try {
            const items = (res?.data?.items || []) as Array<any>;
            // Construir resumen de éxito ANTES de cualquier limpieza
            this.successSummary = this.buildSuccessSummary(items);
            // Mostrar modal de éxito
            this.openSuccessModal();
            for (const it of items) {
              if ((it?.tipo_documento === 'R') && (it?.medio_doc === 'C') && it?.nro_recibo) {
                const fecha = it?.cobro?.fecha_cobro || hoy;
                const anio = new Date(fecha).getFullYear();
                // Descargar PDF sin abrir nueva pestaña
                this.cobrosService.downloadReciboPdf(anio, it.nro_recibo).subscribe({
                  next: (blob) => {
                    const fileName = `recibo_${anio}_${it.nro_recibo}.pdf`;
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = fileName;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                  },
                  error: () => {
                    // Fallback: intentar abrir en nueva pestaña si falla descarga
                    const url = `${environment.apiUrl}/recibos/${anio}/${it.nro_recibo}/pdf`;
                    try { window.open(url, '_blank'); } catch {}
                  }
                });
              }
            }
          } catch {}
          this.showAlert('Cobros registrados', 'success');
          // No limpiar aún; se limpia al cerrar el modal de éxito
        } else {
          this.showAlert(res.message || 'No se pudo registrar', 'warning');
        }
        this.loading = false;
      },
      error: (err: any) => {
        try {
          const detail = {
            status: err?.status,
            message: err?.message,
            backend: err?.error,
            payload
          };
          console.error('Batch error detail:', detail);
        } catch {}
        const backendMsg = (err?.error?.message || '').toString();
        const validationErrors = err?.error?.errors;
        let msg = backendMsg || err?.message || 'Error al registrar cobros';
        if (validationErrors && typeof validationErrors === 'object') {
          const parts: string[] = [];
          for (const k of Object.keys(validationErrors)) {
            const arr = validationErrors[k];
            if (Array.isArray(arr)) parts.push(`${k}: ${arr.join(', ')}`);
          }
          if (parts.length) msg += ` | Detalles: ${parts.join(' | ')}`;
        }
        this.showAlert(msg, 'error');
        this.loading = false;
      }
    });
  }

  // ====== Totales estilo sistema antiguo ======

  calcRowSubtotal(i: number): number {
    const g = this.pagos.at(i) as FormGroup;
    if (!g) return 0;
    const esParcial = !!g.get('es_parcial')?.value;
    if (esParcial) {
      const m = Number(g.get('monto')?.value || 0);
      return m > 0 ? m : 0;
    }
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

  // Opciones válidas para el selector de cantidad por fila
  getCantidadOptions(i: number): number[] {
    try {
      const g = this.pagos.at(i) as FormGroup;
      if (!g) return [1];
      const detalle = (g.get('detalle')?.value || '').toString().toUpperCase();
      // Arrastre: siempre 1
      if (detalle.includes('ARRASTRE')) return [1];
      // Mensualidad: entre 1 y pendientes
      let pendientes = Number(this.resumen?.mensualidad_next?.pending_count ?? 0);
      if (!pendientes || pendientes <= 0) {
        const nroCuotas = Number(this.resumen?.totales?.nro_cuotas || 0);
        const pagadas = Number(this.resumen?.cobros?.mensualidad?.count || 0);
        pendientes = Math.max(0, nroCuotas - pagadas);
      }
      const max = Math.max(1, pendientes);
      const opts: number[] = [];
      for (let n = 1; n <= max; n++) opts.push(n);
      return opts;
    } catch {
      return [1];
    }
  }
  // Añadir la próxima cuota de ARRASTRE al detalle (tabla de pagos)
  addArrastrePago(): void {
    if (!this.resumen || !this.resumen.arrastre || !this.resumen.arrastre.has) {
      this.showAlert('No hay inscripción de arrastre en la gestión seleccionada', 'warning');
      return;
    }
    const next = this.resumen.arrastre.next_cuota;
    const pendientes = Number(this.resumen.arrastre.pending_count || 0);
    if (!next || pendientes <= 0) {
      this.showAlert('No hay cuotas de arrastre pendientes', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    const hoy = new Date().toISOString().slice(0, 10);
    const nro = this.getNextMensualidadNro();
    const monto = Number(next.monto || 0);
    this.pagos.push(this.fb.group({
      nro_cobro: [nro, Validators.required],
      id_cuota: [next.id_cuota_template ?? null],
      id_item: [null],
      monto: [monto, [Validators.required, Validators.min(0)]],
      fecha_cobro: [hoy, Validators.required],
      observaciones: [`Arrastre - Cuota ${next.numero_cuota}`],
      descuento: [0],
      nro_factura: [null],
      nro_recibo: [null],
      pu_mensualidad: [monto],
      order: [0],
      id_asignacion_costo: [next.id_asignacion_costo ?? null],
      // Campos UI (tabla detalle factura)
      cantidad: [1, [Validators.required, Validators.min(1)]],
      detalle: [`Arrastre - Cuota ${next.numero_cuota}`],
      m_marca: [false],
      d_marca: [false]
    }));
    this.showAlert('Cuota de arrastre añadida al lote', 'success');
  }

  private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
    this.alertMessage = message;
    this.alertType = type;
    setTimeout(() => (this.alertMessage = ''), 4000);
  }
}
