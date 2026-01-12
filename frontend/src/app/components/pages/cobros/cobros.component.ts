import { Component, OnInit, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormArray, FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { CobrosService } from '../../../services/cobros.service';
import { GestionService } from '../../../services/gestion.service';
import { ParametrosEconomicosService } from '../../../services/parametros-economicos.service';
import { AuthService } from '../../../services/auth.service';
import { MensualidadModalComponent } from './mensualidad-modal/mensualidad-modal.component';
import { RezagadoModalComponent } from './rezagado-modal/rezagado-modal.component';
import { RecuperacionModalComponent } from './recuperacion-modal/recuperacion-modal.component';
import { ReincorporacionModalComponent } from './reincorporacion-modal/reincorporacion-modal.component';
import { ItemsModalComponent } from './items-modal/items-modal.component';
import { KardexModalComponent } from './kardex-modal/kardex-modal.component';
import { BusquedaEstudianteModalComponent } from './busqueda-estudiante-modal/busqueda-estudiante-modal.component';
import { DescuentoFormModalComponent } from './descuento-form-modal/descuento-form-modal.component';
import { QrPanelComponent } from './qr-panel/qr-panel.component';
import { ClickLockDirective } from '../../../directives/click-lock.directive';
import { environment } from '../../../../environments/environment';
import { saveBlobAsFile, generateQuickReciboPdf, generateQuickFacturaPdf } from '../../../utils/pdf.helpers';
import * as QRCode from 'qrcode';
import { ActivatedRoute } from '@angular/router';
import { forkJoin } from 'rxjs';

@Component({
  selector: 'app-cobros-page',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, MensualidadModalComponent, ItemsModalComponent, RezagadoModalComponent, RecuperacionModalComponent, ReincorporacionModalComponent, BusquedaEstudianteModalComponent, DescuentoFormModalComponent, KardexModalComponent, QrPanelComponent, ClickLockDirective],
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
  descuentoForm: FormGroup;

  // Mensualidades
  mensualidadModalForm: FormGroup;
  mensualidadesPendientes = 0;
  mensualidadPU = 0;
  // Tipo de modal activo
  modalTipo: 'mensualidad' | 'rezagado' | 'recuperacion' | 'arrastre' | 'reincorporacion' = 'mensualidad';

  // Datos
  resumen: any = null;
  gestiones: any[] = [];
  formasCobro: any[] = [];
  sinDocsIdentidad: Array<{ codigo: number; descripcion: string }> = [];
  pensums: any[] = [];
  cuentasBancarias: any[] = [];
  reincorporacion: any = null;
  // Visibilidad del card de Opciones de cobro
  showOpciones = false;
  // Bloqueo de cuota para pagos parciales combinados (EFECTIVO + TARJETA, etc.)
  private lockedMensualidadCuota: number | null = null;
  // Lista filtrada para el modal según selección en cabecera
  modalFormasCobro: any[] = [];
  // Costo de Rezagado (desde costo_semestral)
  rezagadoCosto: number | null = null;
  // Costo de Reincorporación (desde costo_semestral)
  reincorporacionCosto: number | null = null;

  // Estado QR recibido desde el panel QR
  private qrPanelStatus: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado' | null = null;
  private qrPanelActive: boolean = false;
  private qrSavedWaiting: boolean = false;
  private sinQrBaseUrl: string | null = null;

  // Ref del modal de items
  @ViewChild('itemsDlg') itemsDlg?: ItemsModalComponent;
  // Ref del modal de rezagado
  @ViewChild(RezagadoModalComponent) rezagadoDlg?: RezagadoModalComponent;
  // Ref del modal de recuperación
  @ViewChild(RecuperacionModalComponent) recuperacionDlg?: RecuperacionModalComponent;
  @ViewChild(BusquedaEstudianteModalComponent) buscarDlg?: BusquedaEstudianteModalComponent;
  @ViewChild('descuentoDlg') descuentoDlg?: DescuentoFormModalComponent;
  // Ref del panel QR para delegar acciones (guardar en espera)
  @ViewChild('kardexDlg') kardexDlg?: KardexModalComponent;
  @ViewChild('qrPanel') qrPanel?: QrPanelComponent;

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

  busquedaLoading = false;
  busquedaResults: any[] = [];
  busquedaMeta: { page: number; per_page: number; total: number; last_page: number } | null = null;
  busquedaPage = 1;
  busquedaPerPage = 10;
  private busquedaCriteria: { ap_paterno?: string; ap_materno?: string; nombres?: string; ci?: string } = {};
  private qrMetodoSelected: boolean = false;
  private codigoSinBaseSelected: string = '';
  private selectedFormaItem: any | null = null;
  // Saldo restante por cuota gestionado sólo en frontend para pagos parciales
  private frontSaldoByCuota: Record<number, number> = {};
  // Forzar cuota inicial del próximo modal cuando exista saldo parcial pendiente
  private startCuotaOverrideValue: number | null = null;
  metodoPagoLocked: boolean = false;
  descuentoInstitucionalFechaLimite: string | null = null;

  constructor(
    private fb: FormBuilder,
    private cobrosService: CobrosService,
    private auth: AuthService,
    private gestionService: GestionService,
    private peService: ParametrosEconomicosService,
    private route: ActivatedRoute
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
        codigo_sin: [''],
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

    this.descuentoForm = this.fb.group({
      cod_ceta: [''],
      nombre: [''],
      gestion: [''],
      pensum: [''],
      turno: ['']
    });
  }

  // Helper method to extract form errors for debugging
  private getFormErrors(form: FormGroup): any {
    const errors: any = {};
    Object.keys(form.controls).forEach(key => {
      const control = form.get(key);
      if (control instanceof FormGroup) {
        const nestedErrors = this.getFormErrors(control);
        if (Object.keys(nestedErrors).length > 0) {
          errors[key] = nestedErrors;
        }
      } else if (control && control.errors) {
        errors[key] = control.errors;
      }
    });
    return errors;
  }

  // PU a enviar al modal: calcular desde asignacion_costos (bruto - pagado) para la próxima cuota
  getMensualidadPuForModal(): number {
    try {
      const start = this.getNextMensualidadStartCuota();
      // 1) Si hay saldo frontal registrado, usarlo
      const val = this.frontSaldoByCuota[start];
      if (val !== undefined && val !== null) return Number(val || 0);

      // 2) Calcular PU desde asignacion_costos: bruto - pagado (solo si hay saldo pendiente)
      const asignList: any[] = Array.isArray(this.resumen?.asignacion_costos?.items)
        ? this.resumen!.asignacion_costos.items
        : (Array.isArray(this.resumen?.asignaciones) ? this.resumen!.asignaciones : []);

      const hit = asignList.find((a: any) => Number(a?.numero_cuota || 0) === Number(start));
      if (hit) {
        const bruto = this.toNumber(hit?.monto);
        const pagado = this.toNumber(hit?.monto_pagado);
        const descuento = this.toNumber(hit?.descuento);
        const neto = Math.max(0, bruto - descuento);
        const saldo = Math.max(0, neto - pagado);
        // Solo retornar si hay saldo real pendiente
        if (saldo > 0) return saldo;
      }

      // 3) Si mensualidad_next existe, usar su monto
      const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
      if (puNext > 0) return puNext;

      // 4) Fallback: usar PU semestral nominal (para cuotas nuevas sin asignación)
      const puSemestral = Number(this.resumen?.totales?.pu_mensual || 0);
      return puSemestral;
    } catch {
      const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
      const puSemestral = Number(this.resumen?.totales?.pu_mensual || 0);
      return puNext > 0 ? puNext : puSemestral;
    }
  }

  public getFrontSaldos(): Record<number, number> {
    const out: Record<number, number> = {};
    try {
      for (const k of Object.keys(this.frontSaldoByCuota)) {
        const n = Number(k);
        if (isFinite(n)) out[n] = Number(this.frontSaldoByCuota[n] || 0);
      }
    } catch {}
    return out;
  }

  private downloadReciboPdfWithFallback(anio: number, nro: number): void {
    this.cobrosService.downloadReciboPdf(anio, nro).subscribe({
      next: (blob) => saveBlobAsFile(blob, `recibo_${anio}_${nro}.pdf`),
      error: () => {
        const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || (this.resumen?.estudiante?.cod_ceta || '');
        const total = this.totalCobro || 0;
        generateQuickReciboPdf({ anio, nro, codCeta: cod, total });
      }
    });
  }

  private downloadFacturaPdfWithFallback(anio: number, nro: number, item?: any): void {
    this.cobrosService.downloadFacturaPdf(anio, nro).subscribe({
      next: (blob) => saveBlobAsFile(blob, `factura_${anio}_${nro}.pdf`),
      error: () => {
        // Construir PDF rápido con datos disponibles
        try {
          const est = this.resumen?.estudiante || {};
          const razon = (this.identidadForm.get('razon_social')?.value || est.ap_paterno || '').toString();
          const numero = (this.identidadForm.get('ci')?.value || '').toString();
          const complemento = (this.identidadForm.get('complemento_ci')?.value || '').toString();
          const fecha = (item?.cobro?.fecha_cobro || new Date().toISOString()).toString();
          const periodo = (this.resumen?.gestion || '').toString();
          const detalle = (item?.detalle || item?.observaciones || 'Servicio educativo').toString();
          const cant = Number(item?.cantidad || 1);
          const pu = Number(item?.pu_mensualidad || item?.monto || 0);
          const total = Number(item?.monto || 0);
          const pad = (n: number) => String(n).padStart(2, '0');
          const now = new Date();
          const ts = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
          const nick = (this.auth.getCurrentUser()?.nickname) || 'Operador';
          const usuario = `Usuario-Hora: ${nick} - ${ts}`;
          const textoSucursal = this.computeTextoSucursal(est);
          const puntoVentaVar = this.computePuntoVenta();
          const direccionVar = this.computeDireccion();
          const municipioVar = this.computeMunicipio();
          const cufGuess = (item?.cuf || item?.cuf_factura || item?.cuf_final || item?.cuf_siat || undefined) as any;
          // Armar detalle desde las filas del modal si existen; caso contrario, un solo ítem
          let items: Array<{ codigoProducto: string; descripcion: string; nombreUnidadMedida: string; cantidad: number; precioUnitario: number; montoDescuento: number; subTotal: number; }> = [];
          try {
            const rows: Array<any> = Array.isArray(this.successSummary?.rows) ? (this.successSummary as any).rows : [];
            if (rows.length) {
              items = rows.map((r: any, idx: number) => {
                const c = Number(r?.cant ?? 1) || 1;
                const sub = Number(r?.subtotal ?? r?.pu ?? 0) || 0;
                const puCalc = Number(r?.pu ?? (sub / c)) || 0;
                const desc = Number(r?.descuento ?? 0) || 0;
                const descStr = typeof r?.detalle === 'string' ? r.detalle : '';
                return {
                  // Dejar vacío para no mostrar código en el PDF por ahora
                  codigoProducto: '',
                  descripcion: descStr || 'Servicio educativo',
                  nombreUnidadMedida: 'UNIDAD (SERVICIOS)',
                  cantidad: c,
                  precioUnitario: puCalc,
                  montoDescuento: desc,
                  subTotal: sub
                };
              });
            }
          } catch {}
          if (!items.length) {
            items = [
              {
                // Dejar vacío para no mostrar código en el PDF por ahora
                codigoProducto: '',
                descripcion: detalle,
                nombreUnidadMedida: 'UNIDAD (SERVICIOS)',
                cantidad: cant,
                precioUnitario: pu,
                montoDescuento: 0,
                subTotal: total
              }
            ];
          }
          const build = async (meta?: { cuf?: string; leyenda?: string; leyenda2?: string }) => {
            // Generar QR si hay CUF
            let qrBase64: string | null = null;
            if (meta?.cuf) {
              try {
                const nit = '388386029';
                const cuf = meta.cuf;
                const numero = nro;
                const t = 1; // 1 para roll80, 2 para A4/carta
                const backendUrl = (this.sinQrBaseUrl || '').trim();
                const envUrl = (environment as any)?.qrSinUrl || '';
                const base = backendUrl || envUrl || 'https://pilotosiat.impuestos.gob.bo/consulta/QR';
                const sep = base.includes('?') ? '&' : '?';
                const qrUrl = `${base}${sep}nit=${nit}&cuf=${cuf}&numero=${numero}&t=${t}`;
                qrBase64 = await QRCode.toDataURL(qrUrl, {
                  errorCorrectionLevel: 'H',
                  type: 'image/png',
                  width: 200,
                  margin: 1
                });
              } catch (err) {
                console.error('Error generando QR:', err);
              }
            }

            generateQuickFacturaPdf({
            anio,
            nro,
            razon,
            nit: '388386029',
            codigoCliente: numero || (est?.ci ? String(est.ci) : ''),
            fechaEmision: new Date(fecha.replace(' ', 'T')).toLocaleString(),
            periodo,
            nombreEstudiante: [est.nombres, est.ap_paterno, est.ap_materno].filter(Boolean).join(' '),
            detalle,
            cantidad: cant,
            pu,
            descuento: 0,
            montoGift: 0,
            total,
            importeBase: total,
            usuarioHora: usuario,
            qrBase64: qrBase64,
            sucursal: '1',
            puntoVenta: puntoVentaVar,
            direccion: direccionVar,
            telefono: '4581736',
            ciudad: municipioVar,
            codAutorizacion: undefined,
            // adicionales para respetar orden backend
            numeroFactura: nro,
            cuf: (meta?.cuf || cufGuess) as any,
            codigoSucursal: '1',
            codigoPuntoVenta: '2',
            complemento,
            periodoFacturado: periodo,
            descuentoAdicional: 0,
            montoGiftCard: 0,
            montoTotal: total,
            montoTotalSujetoIva: total,
            items,
            leyenda: meta?.leyenda,
            leyenda2: meta?.leyenda2 || '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”',
            totalTexto: undefined,
            codCeta: (this.batchForm.get('cabecera.cod_ceta') as any)?.value || (this.resumen?.estudiante?.cod_ceta || ''),
            formato: 'roll80',
            textoSucursal,
            municipioNombre: municipioVar
            });
          };
          // Obtener meta de factura SIEMPRE para traer leyendas y CUF real
          this.cobrosService.getFacturaMeta(anio, nro).subscribe({
            next: (res: any) => {
              const data = res?.data || {};
              const meta = {
                cuf: (data?.cuf || '').toString() || undefined,
                leyenda: data?.leyenda || undefined,
                leyenda2: data?.leyenda2 || undefined
              };
              build(meta);
            },
            error: () => build(undefined)
          });
        } catch {}
      }
    });
  }

  private computeTextoSucursal(est: any): string {
    try {
      const pensum = (this.resumen as any)?.pensum || '';
      const carrera = ((pensum || '').toString() || '').toUpperCase();
      if (carrera.includes('ELECTRONICA')) return 'CASA MATRIZ';
    } catch {}
    return 'SUCURSAL N. 1';
  }

  private computePuntoVenta(): string {
    try { const pv = (this.resumen as any)?.punto_venta; if (pv !== undefined && pv !== null) return String(pv); } catch {}
    return '0';
  }

  private computeDireccion(): string {
    try { const dir = (this.resumen as any)?.direccion_sucursal; if (dir) return String(dir); } catch {}
    return 'CALLE SAN ALBERTO NRO. 124';
  }

  private computeMunicipio(): string {
    try { const m = (this.resumen as any)?.municipio; if (m) return String(m); } catch {}
    return 'COCHABAMBA';
  }

  private loadGestiones(): void {
    try {
      this.gestionService.getAll().subscribe({
        next: (res) => {
          const arr = Array.isArray((res as any)?.data) ? (res as any).data : (Array.isArray(res) ? res : []);
          this.gestiones = arr.slice().sort((a: any, b: any) => `${b?.gestion}`.localeCompare(`${a?.gestion}`));
        },
        error: () => {
          this.gestionService.getActivas().subscribe({
            next: (r) => { this.gestiones = Array.isArray((r as any)?.data) ? (r as any).data : []; },
            error: () => { this.gestiones = []; }
          });
        }
      });
    } catch { this.gestiones = []; }
  }

  private checkQrPendiente(): void {
    try {
      const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
      if (!cod) return;
      this.cobrosService.stateQrByCodCeta({ cod_ceta: cod }).subscribe({
        next: (res: any) => {
          const d = res?.data || null;
          if (!d || !d.id_qr_transaccion) return;
          const est = (d.estado || '').toString().toLowerCase();
          const saved = !!(d.saved_by_user);
          if (['completado','cancelado','expirado'].includes(est)) return;
          if (['generado','procesando','pendiente'].includes(est)) {
            if (saved) {
              try { this.qrPanel?.setSavedByUser(true, 'Hay un QR en espera. No se puede generar más códigos QR hasta que se complete el que está en espera.'); } catch {}
              return;
            }
          }
          this.cobrosService.getQrTransactionDetail(d.id_qr_transaccion).subscribe({
            next: (det: any) => {
              const tr = det?.data?.transaccion || null;
              if (!tr) return;
              const est2 = (tr.estado || '').toString().toLowerCase();
              if (['completado','cancelado','expirado'].includes(est2)) return;
              const ex = (tr.fecha_expiracion || '').toString();
              let valid = true;
              try { const dt = new Date(ex.replace(' ', 'T')); if (isFinite(dt.getTime())) { valid = dt.getTime() > Date.now(); } } catch {}
              if (!valid) return;
              const base64 = (tr.qr_image_base64 || '').toString();
              if (!base64) return;
              const amt = Number(tr.monto_total || 0);
              const exp = (tr.fecha_expiracion || '').toString();
              const alias = (tr.alias || d.alias || '').toString();
              try { this.qrPanel?.setWarning('El estudiante tiene un QR pendiente. Debe anularlo o pagarlo para generar otro.'); } catch {}
              try { this.qrPanel?.showExisting(alias, base64, amt, exp, tr.estado); } catch {}
            },
            error: () => {}
          });
        },
        error: () => {}
      });
    } catch {}
  }

  // Guardar snapshot del lote mientras el QR está pendiente
  guardarEnEspera(): void {
    try {
      if (this.loading) return;
      // Delegar al panel QR para reusar su lógica y mensajes
      this.qrPanel?.onClickGuardarEspera();
    } catch {}
  }

  // ===================== Reglas de bloqueo por QR =====================
  onQrStatusChange(st: 'pendiente' | 'procesando' | 'completado' | 'expirado' | 'cancelado'): void {
    this.qrPanelStatus = st;
    this.qrPanelActive = true;
    try { console.log('[Cobros] onQrStatusChange', { st, qrPanelActive: this.qrPanelActive }); } catch {}
    if (st === 'completado') {
      try {
        this.showAlert('Pago QR confirmado y lote procesado.', 'success', 8000);
        const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
        if (cod) { try { sessionStorage.removeItem(`qr_session:${cod}:waiting_saved`); } catch {} }
        this.qrSavedWaiting = false;
        // Construir y mostrar el modal de éxito reutilizando las filas actuales del lote
        this.successSummary = this.buildSuccessSummary([]);
        this.openSuccessModal();
        // Descargar documentos generados por el callback (recibo/factura) si existen
        this.downloadQrGeneratedDocs();
      } catch {}
    }
  }

  onQrSavedWaiting(): void {
    this.qrSavedWaiting = true;
    try {
      const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
      if (cod) sessionStorage.setItem(`qr_session:${cod}:waiting_saved`, '1');
    } catch {}
    this.showAlert('Lote guardado en espera. Consulte con administración para la impresión de Recibo/Factura seleccionada cuando el pago QR sea confirmado.', 'success', 10000);
    try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch {}
  }

  private isFormaIdQR(id: any): boolean {
    const s = (id ?? '').toString();
    if (!s) return false;
    const f = (this.formasCobro || []).find((x: any) => `${x?.id_forma_cobro}` === s || `${x?.codigo_sin}` === s);
    const raw = (f?.descripcion_sin ?? f?.descripcion ?? f?.nombre ?? '').toString().trim().toUpperCase();
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    // Detectar QR únicamente por etiqueta explícita 'QR' en el catálogo
    const res = nombre.includes('QR');
    try { console.log('[Cobros] isFormaIdQR', { id: s, label: raw, matchQR: res }); } catch {}
    return res;
  }

  private hasQrInPagos(): boolean {
    try {
      // Regla 1: si el método seleccionado en cabecera es QR y ya hay filas en la tabla, bloquear
      if (this.isQrMetodoSeleccionado() && this.pagos.length > 0) {
        try { console.log('[Cobros] hasQrInPagos: cabecera es QR y hay', this.pagos.length, 'filas'); } catch {}
        return true;
      }
      for (let i = 0; i < this.pagos.length; i++) {
        const g = this.pagos.at(i) as FormGroup;
        const idf = g.get('id_forma_cobro')?.value;
        const isQR = this.isFormaIdQR(idf);
        try { console.log('[Cobros] scan pago', { index: i, id_forma_cobro: idf, isQR }); } catch {}
        if (isQR) return true;
      }
      return false;
    } catch { return false; }
  }

  get qrSaveBlocked(): boolean {
    if (this.qrSavedWaiting) return false;
    // Bloquear si hay pagos con método QR y el estado no está 'completado'
    // Regla A: si el panel QR está activo y hay filas -> bloquear hasta completado
    if (this.qrPanelActive && this.pagos.length > 0) {
      const blockedA = this.qrPanelStatus !== 'completado';
      try { console.log('[Cobros] qrSaveBlocked (panelActive)', { qrPanelStatus: this.qrPanelStatus, blocked: blockedA, len: this.pagos.length }); } catch {}
      return blockedA;
    }
    // Regla B: detección por filas con forma QR
    if (!this.hasQrInPagos()) return false;
    const blocked = this.qrPanelStatus !== 'completado';
    try { console.log('[Cobros] qrSaveBlocked (byRows)', { qrPanelStatus: this.qrPanelStatus, blocked }); } catch {}
    return blocked;
  }

  get isSavedWaiting(): boolean {
    return this.qrSavedWaiting === true;
  }

  get isQrCancelled(): boolean {
    return this.qrPanelStatus === 'cancelado';
  }

  openReincorporacionModal(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.reincorporacion || this.reincorporacion?.debe_reincorporacion !== true) {
      this.showAlert('El estudiante no requiere reincorporación', 'warning');
      return;
    }
    const monto = Number(this.reincorporacionCosto || 0);
    if (!monto || monto <= 0) {
      this.showAlert('No se encontró el costo de Reincorporación en costo_semestral', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    this.computeModalFormasFromSelection();
    this.modalTipo = 'reincorporacion';
    // Abrir modal de Reincorporación
    try {
      const modalEl = document.getElementById('reincorporacionModal');
      const bs = (window as any).bootstrap;
      if (modalEl && bs?.Modal) {
        const modal = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
        modal.show();
      }
    } catch {}
  }

  // Añadir pago de Reincorporación directo al detalle
  addReincorporacionPago(): void {
    if (!this.resumen) {
      this.showAlert('Debe consultar primero un estudiante/gestión', 'warning');
      return;
    }
    if (!this.reincorporacion || this.reincorporacion?.debe_reincorporacion !== true) {
      this.showAlert('El estudiante no requiere reincorporación', 'warning');
      return;
    }
    const monto = Number(this.reincorporacionCosto || 0);
    if (!monto || monto <= 0) {
      this.showAlert('No se encontró el costo de Reincorporación en costo_semestral', 'warning');
      return;
    }
    // Evitar duplicados en el lote actual
    const exists = (this.pagos.controls || []).some(ctrl => {
      const d = ((ctrl as FormGroup).get('detalle') as any)?.value || '';
      return (d || '').toString().toUpperCase().includes('REINCORPORACION');
    });
    if (exists) {
      this.showAlert('Ya agregó Reincorporación al detalle', 'warning');
      return;
    }
    if (!this.ensureMetodoPagoPermitido(['EFECTIVO','TARJETA','CHEQUE','DEPOSITO','TRANSFERENCIA','QR','OTRO'])) return;
    const hoy = new Date().toISOString().slice(0, 10);
    const nro = this.getNextCobroNro();
    this.pagos.push(this.fb.group({
      nro_cobro: [nro, Validators.required],
      id_cuota: [null],
      id_item: [null],
      monto: [monto, [Validators.required, Validators.min(0)]],
      fecha_cobro: [hoy, Validators.required],
      observaciones: ['Reincorporación'],
      descuento: [0],
      nro_factura: [null],
      nro_recibo: [null],
      pu_mensualidad: [monto],
      order: [0],
      id_asignacion_costo: [null],
      // UI
      cantidad: [1, [Validators.required, Validators.min(1)]],
      detalle: ['Reincorporación'],
      m_marca: [false],
      d_marca: [false]
    }));
    this.showAlert('Reincorporación añadida al lote', 'success');
  }

  private updateReincorporacionCosto(): void {
    try {
      const pensum = (this.batchForm.get('cabecera.cod_pensum') as any)?.value || this.resumen?.inscripcion?.cod_pensum || '';
      const gestion = (this.batchForm.get('cabecera.gestion') as any)?.value || this.resumen?.gestion || this.resumen?.inscripcion?.gestion || '';
      if (!pensum) { this.reincorporacionCosto = null; return; }
      this.cobrosService.getCostoSemestralByPensum(pensum, gestion).subscribe({
        next: (res) => {
          if (!res?.success) { this.reincorporacionCosto = null; return; }
          const rows = Array.isArray(res.data) ? res.data : [];
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
            if (t === 'M') t = 'MANANA';
            if (t === 'T') t = 'TARDE';
            if (t === 'N') t = 'NOCHE';
            t = t.normalize('NFD').replace(/\p{Diacritic}/gu, '');
            return t;
          })();
          const semestre = Number(this.resumen?.inscripcion?.semestre ?? this.resumen?.estudiante?.semestre ?? 0) || 0;
          // Filtrar por tipo_costo = 'Reincorporacion'
          const candidatos = rows.filter((r: any) => (r?.tipo_costo || '').toString().toUpperCase() === 'REINCORPORACION');
          const byGestion = candidatos.filter((r: any) => !gestion || `${r?.gestion}` === `${gestion}`);
          const byTurno = (byGestion.length ? byGestion : candidatos).filter((r: any) => {
            if (!turnoInferido) return true;
            const rt = (r?.turno || '').toString().trim().toUpperCase().normalize('NFD').replace(/\p{Diacritic}/gu, '');
            if (rt === turnoInferido) return true;
            if (turnoInferido === 'MANANA' && (rt === 'M')) return true;
            if (turnoInferido === 'TARDE' && (rt === 'T')) return true;
            if (turnoInferido === 'NOCHE' && (rt === 'N')) return true;
            return false;
          });
          const bySemestre = (byTurno.length ? byTurno : (byGestion.length ? byGestion : candidatos)).filter((r: any) => !semestre || Number(r?.semestre || 0) === Number(semestre));
          const pick = (bySemestre[0] || byTurno[0] || byGestion[0] || candidatos[0] || null);
          this.reincorporacionCosto = pick ? Number(pick?.monto_semestre || 0) : null;
        },
        error: () => { this.reincorporacionCosto = null; }
      });
    } catch { this.reincorporacionCosto = null; }
  }

  private sumCobrosMensualidadByGestionInscripciones(): number {
    try {
      const gestion = this.getCurrentGestion();
      const allowed = this.getAllowedInscripcionIds();
      const items: any[] = Array.isArray(this.resumen?.cobros?.mensualidad?.items) ? this.resumen!.cobros.mensualidad.items : [];
      if (!items.length) return 0;
      let sum = 0;
      for (const it of items) {
        const g = `${it?.gestion ?? it?.gestion_cuota ?? ''}`;
        if (gestion && g && `${g}` !== `${gestion}`) continue;
        const ins = `${it?.id_inscripcion ?? it?.inscripcion_id ?? it?.cod_inscrip ?? ''}`;
        if (allowed.size && ins && !allowed.has(ins)) continue;
        sum += this.toNumber(it?.monto);
      }
      return sum;
    } catch { return 0; }
  }

  // Cálculo específico solicitado:
  // - NORMAL: desde 'asignaciones' (monto_pagado) con estados COBRADO o PARCIAL
  // - ARRASTRE: desde 'cobros.mensualidad.items' detectando plantillas de arrastre por id_cuota >= 570
  private computeTotalPagadoExact(): number {
    try {
      const gestion = this.getCurrentGestion();
      // NORMAL
      const asignaciones: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen!.asignaciones : [];
      let normal = 0;
      const normalAsignIds = new Set<number>();
      for (const it of asignaciones) {
        const st = (it?.estado_pago || '').toString().trim().toUpperCase();
        if (st !== 'COBRADO' && st !== 'PARCIAL') continue;
        const g = `${it?.gestion ?? it?.gestion_cuota ?? ''}`;
        if (gestion && g && `${g}` !== `${gestion}`) continue;
        normal += this.toNumber(it?.monto_pagado);
        const ida = Number(it?.id_asignacion_costo || 0);
        if (ida) normalAsignIds.add(ida);
      }
      // ARRASTRE
      const cobros: any[] = Array.isArray(this.resumen?.cobros?.mensualidad?.items) ? this.resumen!.cobros.mensualidad.items : [];
      let arrastre = 0;
      for (const it of cobros) {
        const idCuota = Number(it?.id_cuota || 0);
        if (!idCuota) continue;
        const g = `${it?.gestion ?? it?.gestion_cuota ?? ''}`;
        if (gestion && g && `${g}` !== `${gestion}`) continue;
        // Identificar arrastre: id_asignacion_costo que NO está en las asignaciones (NORMAL)
        const ida = Number(it?.id_asignacion_costo || 0);
        if (!ida) continue; // ignorar items sin asignación vinculada
        if (normalAsignIds.has(ida)) continue; // pertenece a NORMAL, ya está considerado en 'asignaciones'
        arrastre += this.toNumber(it?.monto);
      }
      return normal + arrastre;
    } catch { return 0; }
  }

  private toNumber(v: any): number {
    try {
      if (v === null || v === undefined) return 0;
      if (typeof v === 'number') return isFinite(v) ? v : 0;
      let s = String(v).trim();
      if (!s) return 0;
      // Normalizar: manejar formatos "1.234,56" o "1234,56" o "1234.56"
      const hasComma = s.includes(',');
      const hasDot = s.includes('.');
      if (hasComma && hasDot) {
        // Asumir '.' como miles y ',' como decimal
        s = s.replace(/\./g, '').replace(',', '.');
      } else if (hasComma && !hasDot) {
        // Solo coma: tratar como decimal
        s = s.replace(',', '.');
      }
      // Remover cualquier símbolo no numérico restante (excepto - y .)
      s = s.replace(/[^0-9.\-]/g, '');
      const n = parseFloat(s);
      return isNaN(n) ? 0 : n;
    } catch { return 0; }
  }

  openDescuentoModal(): void {
    try {
      const cod = this.batchForm?.get('cabecera.cod_ceta')?.value || '';
      // Construir nombre en formato: Ap. Paterno Ap. Materno Nombres
      const est = this.resumen?.estudiante || {};
      const nombreOrdenado = [est?.ap_paterno, est?.ap_materno, est?.nombres].filter((x: any) => !!x).join(' ').trim();
      const nombre = nombreOrdenado || this.identidadForm?.get('nombre_completo')?.value || '';
      const gestion = this.batchForm?.get('cabecera.gestion')?.value || (this.resumen?.gestion || '');
      const pensum = this.batchForm?.get('cabecera.cod_pensum')?.value || (this.resumen?.pensum || '');
      try { console.log('[Cobros] openDescuentoModal()', { cod, nombre, gestion, pensum, resumen: this.resumen }); } catch {}
      const turno = (() => {
        // 1) intentar desde identidad (puede ser 'M','T','N' o palabras)
        let t = (this.identidadForm?.get('turno')?.value || '').toString().trim().toUpperCase();
        // 2) intentar desde resumen.inscripcion.cod_curso (última letra)
        if (!t) {
          const codCurso = (this.resumen?.inscripcion?.cod_curso || this.resumen?.inscripciones?.[0]?.cod_curso || '').toString().trim().toUpperCase();
          if (codCurso) t = codCurso.slice(-1);
        }
        // Normalizar a etiqueta visible
        if (t === 'M' || t === 'MANANA') return 'Mañana';
        if (t === 'T' || t === 'TARDE') return 'Tarde';
        if (t === 'N' || t === 'NOCHE') return 'Noche';
        return '';
      })();
      this.descuentoDlg?.open({ cod_ceta: cod, nombre, gestion, pensum, turno });
    } catch {}
  }

  onGuardarDescuento(payload: { cod_ceta: string; nombre: string; gestion: string; pensum: string; turno: string; observaciones?: string }): void {
    console.log('[Cobros] === INICIO onGuardarDescuento ===');
    console.log('[Cobros] payload recibido:', payload);

    try {
      // Validaciones mínimas
      const cod_ceta = (this.batchForm?.get('cabecera.cod_ceta')?.value || '').toString();
      const cod_pensum = (this.batchForm?.get('cabecera.cod_pensum')?.value || this.resumen?.inscripcion?.cod_pensum || '').toString();
      const gestion = (this.batchForm?.get('cabecera.gestion')?.value || this.resumen?.gestion || '').toString();
      const cod_inscrip = Number(this.resumen?.inscripcion?.cod_inscrip || 0);

      console.log('[Cobros] Contexto:', { cod_ceta, cod_pensum, gestion, cod_inscrip });
      console.log('[Cobros] Resumen completo:', this.resumen);

      if (!cod_ceta || !cod_pensum || !cod_inscrip) {
        console.warn('[Cobros] Validación fallida - faltan datos');
        this.showAlert('Debe consultar primero un estudiante/gestión antes de aplicar descuento', 'warning');
        return;
      }

      // 1) Mapear turno -> nombre de parámetro económico
      const key = (() => {
        const t = (payload?.turno || '').toString().toLowerCase();
        if (t.includes('mañana') || t === 'm' || t === 'manana') return 'dinstitucionalmanana';
        if (t.includes('tarde') || t === 't') return 'dinstitucionaltarde';
        if (t.includes('noche') || t === 'n') return 'dinstitucionalnoche';
        return '';
      })();

      console.log('[Cobros] Mapeo turno:', { turno: payload?.turno, key });

      if (!key) {
        console.warn('[Cobros] No se pudo mapear el turno');
        this.showAlert('No se pudo determinar el turno para aplicar el descuento', 'warning');
        return;
      }

      // 2) Obtener cod_beca desde parámetros económicos (valor)
      console.log('[Cobros] Consultando parámetros económicos...');

      this.peService.getAll().subscribe({
        next: (res) => {
          const list = Array.isArray(res?.data) ? res.data : [];
          console.log('[Cobros] Parámetros obtenidos:', list.length, 'items');
          console.log('[Cobros] Buscando parámetro:', key);

          const match = list.find(p => (p?.nombre || '').toString().trim().toLowerCase() === key);
          const cod_beca = match ? Number(match.valor) : NaN;

          console.log('[Cobros] Resultado búsqueda:', { match, cod_beca });

          if (!match || !isFinite(cod_beca)) {
            console.error('[Cobros] No se encontró parámetro económico');
            this.showAlert('No se encontró el parámetro económico para el turno seleccionado', 'error');
            return;
          }

          // 3) Obtener definición desde listado completo
          console.log('[Cobros] Iniciando búsqueda de definición con cod_beca:', cod_beca);

          const proceed = (def: any) => {
                console.log('[Cobros] === PROCESANDO DEFINICIÓN ===');
                console.log('[Cobros] Definición encontrada:', def);

                // 4) Construir cuotas objetivo: SOLO mensualidades normales (excluir arrastres)
                const pendientes: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen!.asignaciones : [];
                const arrastres: any[] = Array.isArray(this.resumen?.asignaciones_arrastre) ? this.resumen!.asignaciones_arrastre : [];

                console.log('[Cobros] Cuotas en resumen:');
                console.log('  - Asignaciones normales:', pendientes.length);
                console.log('  - Arrastres:', arrastres.length);
                console.log('  - Detalle asignaciones:', pendientes);
                console.log('  - Detalle arrastres:', arrastres);

                // Filtrar solo cuotas normales pendientes
                const cuotasTarget = pendientes.filter((a: any) => {
                  const st = (a?.estado_pago || '').toString().trim().toUpperCase();
                  const numCuota = Number(a?.numero_cuota || 0);

                  console.log(`[Cobros] Evaluando cuota ${numCuota}:`, {
                    estado: st,
                    cobrado: st === 'COBRADO',
                    numero_valido: numCuota >= 1
                  });

                  // Excluir cobradas
                  if (st === 'COBRADO') {
                    console.log(`  -> Excluida: ya cobrada`);
                    return false;
                  }

                  // Solo incluir mensualidades normales
                  if (numCuota < 1) {
                    console.log(`  -> Excluida: número de cuota inválido`);
                    return false;
                  }

                  console.log(`  -> INCLUIDA`);
                  return true;
                });

                console.log('[Cobros] Resultado filtrado:');
                console.log('  - Total pendientes:', pendientes.length);
                console.log('  - Total filtradas:', cuotasTarget.length);
                console.log('  - Cuotas seleccionadas:', cuotasTarget);

                if (!cuotasTarget.length) {
                  console.warn('[Cobros] No hay cuotas para aplicar descuento');
                  this.showAlert('No hay cuotas de mensualidad pendientes en la gestión seleccionada', 'warning');
                  return;
                }

                const toNum = (v: any) => { try { if (v == null) return 0; const n = Number(v); return isFinite(n) ? n : 0; } catch { return 0; } };
                const isPct = !!def?.porcentaje;

                console.log('[Cobros] Calculando descuentos:');
                console.log('  - Es porcentaje:', isPct);
                console.log('  - Monto/Porcentaje:', def?.monto);

                const cuotasPayload = cuotasTarget.map((c: any) => {
                  const monto = Math.max(0, toNum(c?.monto) - toNum(c?.monto_pagado));
                  let md = 0;
                  if (isPct) md = +(monto * (toNum(def?.monto) / 100)).toFixed(2);
                  else md = Math.min(monto, toNum(def?.monto));

                  console.log(`  - Cuota ${c?.numero_cuota}: monto=${monto}, descuento=${md}`);

                  return {
                    numero_cuota: Number(c?.numero_cuota || 0),
                    id_cuota: (c?.id_cuota != null ? Number(c.id_cuota) : null),
                    monto_descuento: md,
                    observaciones: 'Descuento institucional automático'
                  };
                }).filter((r: any) => r.numero_cuota > 0 && r.monto_descuento > 0);

                console.log('[Cobros] Payload cuotas final:', cuotasPayload);

                if (!cuotasPayload.length) {
                  console.warn('[Cobros] No hay montos a descontar');
                  this.showAlert('No hay monto a descontar en las cuotas seleccionadas', 'warning');
                  return;
                }

                const idUsuario = Number(this.auth?.getCurrentUser()?.id_usuario || 0);

                // Fecha actual en formato yyyy-mm-dd
                const now = new Date();
                const fechaSolicitud = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

                const payloadAssign = {
                  cod_ceta,
                  cod_pensum,
                  cod_inscrip,
                  id_usuario: idUsuario,
                  cod_beca: Number(def.cod_beca),
                  nombre: String(def?.nombre_beca || 'Descuento'),
                  porcentaje: toNum(def?.monto), // el backend usará cuotas.monto_descuento
                  observaciones: (payload?.observaciones ? String(payload.observaciones).trim() : 'Descuento institucional aplicado desde formulario'),
                  tipo_inscripcion: String(this.resumen?.inscripcion?.tipo_inscripcion || ''),
                  fechaSolicitud: fechaSolicitud,
                  cuotas: cuotasPayload
                };

                console.log('[Cobros] === PAYLOAD FINAL ===');
                console.log('[Cobros] Payload assignDescuento:', payloadAssign);

                this.cobrosService.assignDescuento(payloadAssign).subscribe({
                  next: () => {
                    console.log('[Cobros] ✓ Descuento aplicado exitosamente');
                    this.showAlert('Descuento aplicado correctamente', 'success');
                    this.cobrosService.getResumen(cod_ceta, gestion).subscribe({
                      next: (r) => { if (r?.success) { this.resumen = r.data; } },
                      error: () => {}
                    });
                  },
                  error: (err) => {
                    console.error('assignDescuento error', err);
                    const msg = err?.error?.message || 'No se pudo asignar el descuento';
                    this.showAlert(msg, 'error');
                  }
                });
          };

          // Combinar becas (beca=1) y descuentos (beca=0) de la misma tabla
          console.log('[Cobros] Consultando definiciones (becas + descuentos)...');

          forkJoin({
            becas: this.cobrosService.getDefBecas(),
            descuentos: this.cobrosService.getDefDescuentos()
          }).subscribe({
            next: ({ becas, descuentos }) => {
              const defsBecas = Array.isArray((becas as any)?.data) ? (becas as any).data : [];
              const defsDescuentos = Array.isArray((descuentos as any)?.data) ? (descuentos as any).data : [];

              console.log('[Cobros] Definiciones recibidas:');
              console.log('  - Becas (beca=1):', defsBecas.length);
              console.log('  - Descuentos (beca=0):', defsDescuentos.length);

              // Normalizar descuentos al mismo esquema que becas
              const defsDescNormalizados = defsDescuentos.map((d: any) => ({
                cod_beca: d?.cod_descuento != null ? Number(d.cod_descuento) : d?.cod_beca,
                nombre_beca: d?.nombre_descuento || d?.nombre_beca || '',
                descripcion: d?.descripcion ?? null,
                monto: d?.monto != null && d.monto !== '' ? Number(d.monto) : 0,
                porcentaje: typeof d?.porcentaje === 'boolean' ? d.porcentaje : d?.porcentaje == 1,
                estado: typeof d?.estado === 'boolean' ? d.estado : d?.estado == 1
              }));

              const defs = [...defsBecas, ...defsDescNormalizados];
              const codBecasDisponibles = defs.map((d: any) => Number(d?.cod_beca));

              console.log('[Cobros] Definiciones combinadas:');
              console.log('  - Total:', defs.length);
              console.log('  - Buscando cod_beca:', cod_beca);
              console.log('  - IDs disponibles:', codBecasDisponibles);
              console.log('  - Todas las definiciones:', defs);

              const def = defs.find((d: any) => Number(d?.cod_beca) === Number(cod_beca));

              console.log('[Cobros] Resultado búsqueda definición:');
              console.log('  - Encontrada:', !!def);
              console.log('  - Definición:', def);

              if (!def) {
                console.error('[Cobros] Definición no encontrada');
                this.showAlert('No existe la definición de beca/descuento solicitada (cod_beca: ' + cod_beca + '). Disponibles: ' + codBecasDisponibles.join(', '), 'error');
                return;
              }

              proceed(def);
            },
            error: (err) => {
              console.error('[Cobros] Error al obtener definiciones:', err);
              this.showAlert('No se pudo obtener catálogo de becas/descuentos', 'error');
            }
          });
        },
        error: (err) => { console.error('[Cobros] getAll ParametrosEconomicos error', err); this.showAlert('No se pudo obtener parámetros económicos', 'error'); }
      });
    } catch (e) {
      console.error('[Cobros] onGuardarDescuento fatal', e);
      this.showAlert('Error inesperado al aplicar descuento', 'error');
    }
  }

  closeDescuentoModal(): void {
    try {
      const modalEl = document.getElementById('descuentoFormModal');
      const bs = (window as any).bootstrap;
      if (modalEl && bs?.Modal) {
        const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
        instance.hide();
      }
    } catch {}
  }

  saveDescuentoForm(): void {
    try {
      const data = this.descuentoForm?.value || {};
      this.showAlert('Formulario de descuento guardado', 'success');
      this.closeDescuentoModal();
    } catch {
      this.showAlert('No se pudo guardar el formulario de descuento', 'error');
    }
  }

  // ================== Búsqueda de estudiante por nombre/CI ==================
  openBusquedaModal(): void {
    try { this.buscarDlg?.open(); } catch {}
  }

  onBuscarEstudiantes(criteria: { ap_paterno?: string; ap_materno?: string; nombres?: string; ci?: string }): void {
    this.busquedaCriteria = { ...criteria };
    this.busquedaLoading = true;
    this.busquedaResults = [];
    this.cobrosService.searchEstudiantes({ ...criteria, page: this.busquedaPage, per_page: this.busquedaPerPage }).subscribe({
      next: (res) => {
        this.busquedaResults = Array.isArray(res?.data) ? res.data : [];
        this.busquedaMeta = res?.meta || null;
        this.busquedaLoading = false;
      },
      error: () => { this.busquedaLoading = false; this.busquedaResults = []; }
    });
  }

  onSeleccionarEstudiante(row: any): void {
    try { this.buscarDlg?.close(); } catch {}
    const cod = (row?.cod_ceta ?? row?.codCeta ?? row?.codigo ?? '').toString();
    if (!cod) return;
    this.searchForm.patchValue({ cod_ceta: cod }, { emitEvent: false });
    this.loadResumen();
  }

  onBusquedaPageChange(p: number): void {
    this.busquedaPage = p;
    this.onBuscarEstudiantes(this.busquedaCriteria);
  }

  onBusquedaPerPageChange(n: number): void {
    this.busquedaPerPage = n;
    this.busquedaPage = 1;
    this.onBuscarEstudiantes(this.busquedaCriteria);
  }

  // =============== Resumen económico (sidebar) ===============
  get cuotasPendientes(): Array<any> {
    const arr = (this.resumen?.asignaciones || []) as Array<any>;
    return arr.filter(a => {
      const st = (a?.estado_pago || '').toString().toUpperCase();
      return st !== 'COBRADO';
    });
  }

  estadoEtiqueta(a: any): string {
    const st = (a?.estado_pago || '').toString().toUpperCase();
    if (st === 'PARCIAL') return 'Pagado Parcial';
    if (st === 'COBRADO') return 'Pagado';
    if (st === 'VENCIDO') return 'Vencido';
    return 'Sin pagar';
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
      this.showAlert('No se pudo agregar el item (payload vacío)', 'error');
      return;
    }
    const existingDoc = this.getCurrentDocTipoInForm();
    if (existingDoc === 'MIXED') {
      this.showAlert('El detalle ya contiene FACTURA y RECIBO. Elimine o unifique antes de agregar más.', 'error');
      return;
    }
    const incomingDocs = this.collectDocTiposFromArray(pagos);
    const incomingDoc = this.resolveUniformDocTipo(incomingDocs);
    if (incomingDoc === null) {
      this.showAlert('No se puede mezclar FACTURA y RECIBO en el mismo agregado. Seleccione un único tipo.', 'error');
      return;
    }
    if ((existingDoc === 'F' || existingDoc === 'R') && incomingDoc && incomingDoc !== existingDoc) {
      const label = existingDoc === 'F' ? 'FACTURA' : 'RECIBO';
      this.showAlert(`Ya hay líneas con ${label}. Debe ingresar el mismo tipo de documento (${label}).`, 'error');
      return;
    }
    if ((existingDoc === 'F' || existingDoc === 'R') && !incomingDoc) {
      const label = existingDoc === 'F' ? 'FACTURA' : 'RECIBO';
      this.showAlert(`Debe seleccionar tipo de documento ${label} para continuar.`, 'error');
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
    // Preferir NETO desde la lista de asignaciones_arrastre o asignacion_costos del bloque 'arrastre'
    let arrNet = 0;
    try {
      const numero = Number((next as any)?.numero_cuota || 0);
      const ida = Number((next as any)?.id_asignacion_costo || 0);
      const idt = Number((next as any)?.id_cuota_template || 0);
      // 1) Buscar en arrastre.asignacion_costos.items
      const acItems: any[] = Array.isArray(this.resumen?.arrastre?.asignacion_costos?.items)
        ? this.resumen!.arrastre.asignacion_costos.items : [];
      let hit: any = null;
      if (acItems.length) hit = acItems.find((a: any) => {
        const n = Number(a?.numero_cuota || a?.numero || 0);
        const _ida = Number(a?.id_asignacion_costo || 0);
        const _idt = Number(a?.id_cuota_template || 0);
        return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
      }) || null;
      // 2) Fallback: buscar en arrastre.asignaciones_arrastre
      if (!hit) {
        const arrAsigs: any[] = Array.isArray(this.resumen?.arrastre?.asignaciones_arrastre)
          ? this.resumen!.arrastre.asignaciones_arrastre : [];
        hit = arrAsigs.find((a: any) => {
          const n = Number(a?.numero_cuota || 0);
          const _ida = Number(a?.id_asignacion_costo || 0);
          const _idt = Number(a?.id_cuota_template || 0);
          return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
        }) || null;
      }
      // 2.b) Fallback adicional: raíz resumen.asignaciones_arrastre
      if (!hit) {
        const rootArr: any[] = Array.isArray((this.resumen as any)?.asignaciones_arrastre)
          ? (this.resumen as any).asignaciones_arrastre : [];
        hit = rootArr.find((a: any) => {
          const n = Number(a?.numero_cuota || 0);
          const _ida = Number(a?.id_asignacion_costo || 0);
          const _idt = Number(a?.id_cuota_template || 0);
          return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
        }) || null;
      }
      if (hit) {
        const bruto = this.toNumber((hit as any)?.monto);
        const desc = this.toNumber((hit as any)?.descuento);
        const neto = this.toNumber((hit as any)?.monto_neto);
        arrNet = neto > 0 ? neto : Math.max(0, bruto - desc);
      }
      // 3) Intentar con campos del objeto 'arrastre' (algunos backends colocan la cuota activa aquí)
      if (!(arrNet > 0)) {
        const arrObj: any = (this.resumen as any)?.arrastre || null;
        if (arrObj) {
          const brutoArr = this.toNumber(arrObj?.monto);
          const descArr = this.toNumber(arrObj?.descuento);
          const netoArr = this.toNumber(arrObj?.monto_neto);
          if (netoArr > 0) arrNet = netoArr; else if (brutoArr > 0 && descArr > 0) arrNet = Math.max(0, brutoArr - descArr);
        }
      }
      // 4) Último recurso: usar campos de next
      if (!(arrNet > 0)) {
        const brutoNext = this.toNumber((next as any)?.monto);
        const descNext = this.toNumber((next as any)?.descuento);
        const netoNext = this.toNumber((next as any)?.monto_neto);
        arrNet = netoNext > 0 ? netoNext : Math.max(0, brutoNext - descNext);
      }
    } catch { arrNet = Number((next as any)?.monto || 0); }
    this.mensualidadPU = arrNet;
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
    const docs: any[] = Array.isArray(s.docs) ? s.docs : [];
    const facturasHtml = docs.filter((d: any) => d && d.nro_factura)
      .map((d: any) => {
        const anio = d.anio ?? '';
        const nro = d.nro_factura ?? '';
        const cod = (d.codigo_recepcion ?? '').toString();
        const codShort = cod ? `${cod.substring(0, 8)}...${cod.substring(cod.length - 6)}` : '';
        return `
          <div class="small py-1">
            <strong>Factura:</strong> ${anio}-${nro}
            ${cod ? ` | <strong>Código Recepción:</strong> <span title="${cod}">${codShort}</span>` : ''}
          </div>`;
      }).join('');
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
                ${facturasHtml ? `<div class="mt-2"><div class="panel-header">Comprobantes emitidos</div>${facturasHtml}</div>` : ''}
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
          pu: this.getRowDisplayPU(idx),
          descuento: this.getRowDisplayDescuento(idx),
          subtotal: this.calcRowSubtotal(idx),
          obs: (g.get('observaciones')?.value || '').toString()
        };
      });
      const total = rows.reduce((acc, r) => acc + Number(r.subtotal || 0), 0);
      const seen = new Set<string>();
      const docs: any[] = [];
      for (const it of (createdItems || [])) {
        try {
          const fecha = it?.cobro?.fecha_cobro || new Date().toISOString().slice(0,10);
          const anio = new Date(fecha).getFullYear();
          if ((it?.tipo_documento === 'R') && (it?.medio_doc === 'C') && it?.nro_recibo) {
            const key = `R:${anio}:${it?.nro_recibo}`;
            if (!seen.has(key)) { docs.push({ tipo: 'R', anio, nro_recibo: it?.nro_recibo }); seen.add(key); }
          } else if ((it?.tipo_documento === 'F') && (it?.medio_doc === 'C') && it?.nro_factura) {
            const key = `F:${anio}:${it?.nro_factura}`;
            if (!seen.has(key)) { docs.push({ tipo: 'F', anio, nro_factura: it?.nro_factura, codigo_recepcion: it?.codigo_recepcion }); seen.add(key); }
          }
        } catch {}
      }
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
      const docs: any[] = (this.successSummary?.docs || []) as any[];
      if (docs.length) {
        const firstFactura = docs.find((x: any) => x?.nro_factura && x?.anio);
        const firstRecibo = docs.find((x: any) => x?.nro_recibo && x?.anio);
        const d: any = firstFactura || firstRecibo || null;
        if (d) {
          try {
            const base = this.apiBase();
            const url = firstFactura
              ? `${base}/facturas/${d.anio}/${d.nro_factura}/pdf`
              : `${base}/recibos/${d.anio}/${d.nro_recibo}/pdf`;
            const a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            document.body.appendChild(a);
            a.click();
            a.remove();
            return;
          } catch {}
        }
      }
    } catch {}
    try { window.print(); } catch {}
  }

  private apiBase(): string {
    try {
      const protocol = typeof window !== 'undefined' && window.location ? (window.location.protocol || 'http:') : 'http:';
      const host = typeof window !== 'undefined' && window.location ? (window.location.hostname || 'localhost') : 'localhost';
      const port = environment.apiPort || '8069';
      return `${protocol}//${host}:${port}/api`;
    } catch {
      return environment.apiUrl;
    }
  }

  private downloadQrGeneratedDocs(): void {
    try {
      const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
      if (!cod) return;
      this.cobrosService.stateQrByCodCeta({ cod_ceta: cod }).subscribe({
        next: (res: any) => {
          const d = res?.data || null;
          const id = d?.id_qr_transaccion || null;
          if (!id) return;
          this.cobrosService.getQrTransactionDetail(id).subscribe({
            next: (det: any) => {
              try {
                const tr = det?.data?.transaccion || null;
                const anio = Number(tr?.anio_recibo || 0);
                const nro = Number(tr?.nro_recibo || 0);
                if (anio && nro) {
                  try {
                    if (this.successSummary) {
                      const docs = Array.isArray(this.successSummary.docs) ? this.successSummary.docs : [];
                      if (!docs.some((x: any) => Number(x?.anio) === anio && Number(x?.nro_recibo) === nro)) {
                        docs.push({ anio, nro_recibo: nro });
                        this.successSummary.docs = docs as any;
                      }
                    }
                  } catch {}
                  this.downloadReciboPdfWithFallback(anio, nro);
                }
              } catch {}
            },
            error: () => {}
          });
        },
        error: () => {}
      });
    } catch {}
  }

  onSuccessClose(): void {
    // Recargar datos del resumen antes de limpiar para mostrar actualizados
    const cod = (this.searchForm.get('cod_ceta')?.value || '').toString().trim();
    const gestion = (this.searchForm.get('gestion')?.value || '').toString().trim();

    if (cod) {
      this.cobrosService.getResumen(cod, gestion).subscribe({
        next: (res) => {
          if (res?.success) {
            this.resumen = res.data;
            this.showOpciones = true;
            // Limpiar solo el formulario de cobros, pero mantener el resumen actualizado
            this.limpiarFormularioCobros();
            this.showAlert('Datos actualizados. Puede ver los nuevos pagos en el Kardex económico.', 'success');
          } else {
            // Si falla la recarga, limpiar todo como antes
            this.limpiarTodo();
          }
        },
        error: () => {
          // Si falla la recarga, limpiar todo como antes
          this.limpiarTodo();
        }
      });
    } else {
      // Si no hay código, limpiar todo
      this.limpiarTodo();
    }

    // Cerrar modal
    const modalEl = document.getElementById('successModal');
    const bs = (window as any).bootstrap;
    if (modalEl && bs?.Modal) {
      const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
      instance.hide();
    }
  }

  private limpiarFormularioCobros(): void {
    try {
      (this.batchForm.get('pagos') as FormArray).clear();
      this.batchForm.reset({ cabecera: { id_forma_cobro: '', id_cuentas_bancarias: '' }, pagos: [] });
      this.identidadForm.reset({ nombre_completo: '', tipo_identidad: 1, ci: '', complemento_habilitado: false, complemento_ci: '', razon_social: '', email_habilitado: false, email: '', turno: '' });
      this.modalIdentidadForm.reset({ tipo_identidad: 1, ci: '', complemento_habilitado: false, complemento_ci: '', razon_social: '' });
      this.mensualidadModalForm.reset({ metodo_pago: '', cantidad: 1, costo_total: 0, observaciones: '' });
      this.alertMessage = '';
      this.metodoPagoLocked = false;
      this.successSummary = null;
      try { (this.batchForm.get('cabecera.codigo_sin') as any)?.enable?.({ emitEvent: false }); } catch {}
    } catch {}
  }

  private limpiarTodo(): void {
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
      this.metodoPagoLocked = false;
      this.successSummary = null;
      try { (this.batchForm.get('cabecera.codigo_sin') as any)?.enable?.({ emitEvent: false }); } catch {}
    } catch {}
  }

  openQrSavedWaitingConfirmModal(): void {
    try {
      const modalEl = document.getElementById('qrSavedWaitingConfirmModal');
      const bs = (window as any).bootstrap;
      if (modalEl && bs?.Modal) {
        const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl, { backdrop: 'static', keyboard: false });
        instance.show();
      }
    } catch {}
  }

  confirmQrSavedWaitingRefresh(): void {
    try {
      const modalEl = document.getElementById('qrSavedWaitingConfirmModal');
      const bs = (window as any).bootstrap;
      if (modalEl && bs?.Modal) {
        const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
        instance.hide();
      }
    } catch {}
    try {
      const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
      if (cod) {
        try { sessionStorage.removeItem(`qr_session:${cod}:waiting_saved`); } catch {}
        try { sessionStorage.removeItem(`qr_session:${cod}`); } catch {}
      }
    } catch {}
    try { window.location.reload(); } catch {}
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

    this.loadGestiones();
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
    this.formasCobro = [];
    this.cobrosService.getFormasCobro().subscribe({
      next: (res) => {
        if (!res?.success) { this.formasCobro = []; return; }
        const raw = Array.isArray(res.data) ? res.data : [];
        // Filtrar solo activas usando únicamente 'activo' y con codigo_sin válido
        const actives = raw.filter((r: any) => {
          const codigo = (r?.codigo_sin ?? '').toString().trim();
          if (!codigo) return false; // sin código no se muestra
          const vals = [r?.activo, r?.estado, r?.habilitado];
          const norm = (x: any) => {
            if (x === null || x === undefined) return '';
            if (x === true) return 'TRUE';
            if (x === false) return 'FALSE';
            const s = String(x).trim().toUpperCase();
            return s;
          };
          const normalized = vals.map(norm);
          const anyActive = normalized.some(s => s === '1' || s === 'TRUE' || s === 'ACTIVO');
          const anyInactive = normalized.some(s => s === '0' || s === 'FALSE' || s === 'INACTIVO');
          return anyActive && !anyInactive;
        });
        // Orden ascendente por codigo_sin y luego por descripcion/nombre
        this.formasCobro = actives.slice().sort((a: any, b: any) => {
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
        if (idSel && !((cab.get('codigo_sin')?.value ?? '').toString())) {
          const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === idSel);
          if (match) {
            cab.patchValue({ codigo_sin: match.codigo_sin }, { emitEvent: false });
            this.codigoSinBaseSelected = this.getBaseCodigoSinForSiat(match);
          }
        }
        // Calcular opciones del modal según selección actual
        this.computeModalFormasFromSelection();
      },
      error: () => { this.formasCobro = []; }
    });
    // Cargar cuentas bancarias para métodos de pago como TARJETA/DEPÓSITO
    this.cobrosService.getCuentasBancarias().subscribe({
      next: (res) => { if (res.success) this.cuentasBancarias = res.data; },
      error: () => {}
    });

    this.cargarParametrosDescuentoInstitucional();

    // Leer cod_ceta (y gestion) desde querystring para soportar deep-link desde SGA
    try {
      this.route.queryParamMap.subscribe((params) => {
        const cod = (params.get('cod_ceta') || '').toString().trim();
        const ges = (params.get('gestion') || '').toString().trim();
        if (cod) {
          this.searchForm.patchValue({ cod_ceta: cod }, { emitEvent: false });
          if (ges) {
            this.searchForm.patchValue({ gestion: ges }, { emitEvent: false });
          }
          this.loadResumen();
        }
      });
    } catch {}

    // Recalcular costo total de mensualidades cuando cambie la cantidad
    this.mensualidadModalForm.get('cantidad')?.valueChanges.subscribe((v: number) => {
      this.recalcMensualidadTotal();
      // Calcular costo de Rezagado desde costo_semestral
      this.updateRezagadoCosto();
      // Calcular costo de Reincorporación desde costo_semestral
      this.updateReincorporacionCosto();
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
    // Antes de eliminar, si la fila es una Mensualidad parcial, reintegrar el monto al saldo frontal
    try {
      const ctrl = this.pagos.at(i) as FormGroup;
      const detalle = (ctrl.get('detalle')?.value || '').toString();
      const isMensualidad = /^\s*Mensualidad\s*-/i.test(detalle);
      const esParcial = !!ctrl.get('es_parcial')?.value;
      const numeroCuota = Number(ctrl.get('numero_cuota')?.value || 0);
      const pu = Number(ctrl.get('pu_mensualidad')?.value || 0);
      if (isMensualidad) {
        if (esParcial && numeroCuota) {
          const monto = this.calcRowSubtotal(i);
          const prevSaldo = Number(this.frontSaldoByCuota[numeroCuota] || 0);
          const desc = Number(ctrl.get('descuento')?.value || 0) || 0;
          const base = pu > 0 ? pu : Number(this.resumen?.totales?.pu_mensual || this.mensualidadPU || 0);
          const netoBase = Math.max(0, base - (isNaN(desc) ? 0 : desc));
          let nuevoSaldo = (isFinite(prevSaldo) ? prevSaldo : 0) + (isFinite(monto) ? monto : 0);
          // Reintegrar respetando el neto (PU - descuento) de esa línea parcial
          if (netoBase > 0 && nuevoSaldo > netoBase) nuevoSaldo = netoBase;
          this.frontSaldoByCuota[numeroCuota] = nuevoSaldo;
          // Fijar foco en la misma cuota nuevamente
          this.lockedMensualidadCuota = numeroCuota;
          this.startCuotaOverrideValue = numeroCuota;
          // Actualizar PU sugerido para el próximo modal
          if (nuevoSaldo > 0) {
            this.mensualidadPU = nuevoSaldo;
          } else {
            const puSem = Number(this.resumen?.totales?.pu_mensual || 0);
            const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
            this.mensualidadPU = puSem > 0 ? puSem : puNext;
          }
        }
      }
    } catch {}
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
          try {
            console.log('[RESUMEN] recuperacion', this.resumen?.recuperacion);
            console.log('[RESUMEN] recuperacion_pendiente', this.resumen?.recuperacion_pendiente);
            console.log('[RESUMEN] gestion/pensum', this.resumen?.gestion, this.resumen?.inscripcion?.cod_pensum);
          } catch {}
          this.showOpciones = true;
          this.showAlert('Resumen cargado', 'success');
          // Mostrar advertencias de backend si existen (fallback de gestión u otros)
          const warnings = (this.resumen?.warnings || []) as string[];
          if (Array.isArray(warnings) && warnings.length) {
            this.showAlert(warnings.join(' | '), 'warning');
          }
          // Prefill identidad/razón social
          const est = this.resumen?.estudiante || {};
          const fullName = [est.ap_paterno, est.ap_materno, est.nombres ].filter(Boolean).join(' ');
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

          // Consultar estado de Reincorporación (SGA) para este estudiante
          try {
            const codPensum = (ins.cod_pensum ?? est.cod_pensum ?? '').toString();
            const gestionEval = (this.resumen?.gestion ?? ins.gestion ?? gestion ?? '').toString();
            if ((est.cod_ceta || cod_ceta) && codPensum) {
              this.cobrosService.getReincorporacionEstado({ cod_ceta: (est.cod_ceta || cod_ceta), cod_pensum: codPensum, gestion: gestionEval }).subscribe({
                next: (r) => { this.reincorporacion = r?.data || r || null; },
                error: () => { this.reincorporacion = null; }
              });
            } else {
              this.reincorporacion = null;
            }
          } catch { this.reincorporacion = null; }

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
          // - PU base desde backend y override por saldo en frontend si existe para la cuota bloqueada
          const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
          let puBase = puNext > 0 ? puNext : Number(this.resumen?.totales?.pu_mensual || 0);
          const locked = this.lockedMensualidadCuota as number | null;
          if (locked && this.frontSaldoByCuota[locked] !== undefined) {
            puBase = this.frontSaldoByCuota[locked];
          }
          this.mensualidadPU = puBase;
          // Inicializar modal de mensualidades
          const defaultMetodo = (this.batchForm.get('cabecera.id_forma_cobro') as any)?.value || '';
          this.mensualidadModalForm.patchValue({
          }, { emitEvent: false });
          // Calcular costos contextuales
          this.updateRezagadoCosto();
          this.updateReincorporacionCosto();
        } else {
          // Sin coincidencia: marcar sin información
          this.identidadForm.patchValue({ ci: 'SIN INFORMACIÓN' }, { emitEvent: false });
        }
        this.loading = false;
      },
      error: (err) => {
        console.error('Resumen error:', err);
        const status = Number(err?.status || 0);
        const backendMsg = (err?.error?.message || err?.message || '').toString();
        // Fallback: si la gestión solicitada no aplica, reintentar con la última inscripción del estudiante
        if (status === 404 && backendMsg.toLowerCase().includes('no tiene inscripción en la gestión solicitada')) {
          try {
            const cod = this.searchForm.get('cod_ceta')?.value;
            // limpiar gestion seleccionada y reintentar sin gestion
            this.searchForm.patchValue({ gestion: '' }, { emitEvent: false });
            this.cobrosService.getResumen(cod).subscribe({
              next: (res2) => {
                if (res2?.success) {
                  this.resumen = res2.data;
                  this.showOpciones = true;
                  this.showAlert('Se usó la última inscripción del estudiante', 'warning');
                  // Prefill cabecera con la nueva respuesta
                  const est = this.resumen?.estudiante || {};
                  const ins = this.resumen?.inscripcion || {};
                  (this.batchForm.get('cabecera') as FormGroup).patchValue({
                    cod_ceta: est.cod_ceta || cod,
                    cod_pensum: ins.cod_pensum ?? est.cod_pensum ?? '',
                    tipo_inscripcion: ins.tipo_inscripcion || '',
                    gestion: this.resumen?.gestion ?? ins.gestion ?? ''
                  });
                  // Actualizar costos contextuales tras fallback
                  this.updateRezagadoCosto();
                  this.updateReincorporacionCosto();
                } else {
                  this.resumen = null; this.reincorporacion = null; this.showOpciones = false;
                }
                this.loading = false;
              },
              error: (e2) => {
                this.resumen = null; this.reincorporacion = null; this.showOpciones = false;
                this.showAlert((e2?.error?.message || 'Error al obtener resumen (fallback)') as string, 'error');
                this.loading = false;
              }
            });
            return;
          } catch {}
        }
        // Caso general: mostrar mensajes como antes
        this.resumen = null;
        this.reincorporacion = null;
        this.showOpciones = false;
        if (status === 404) {
          this.showAlert(backendMsg || 'Estudiante no encontrado', 'warning');
        } else if (status === 422) {
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
    if (this.metodoPagoLocked) {
      return;
    }
    const sel = (ev?.target?.value ?? '').toString(); // codigo_sin
    const cab = this.batchForm.get('cabecera') as FormGroup;
    cab.patchValue({ codigo_sin: sel }, { emitEvent: false });
    // Determinar opción exacta seleccionada por el usuario usando el texto del option
    const optEl: any = ev?.target?.selectedOptions?.[0] || null;
    const optText = (optEl?.text || '').toString();
    const optLabelNorm = this.normalizeLabel(optText);
    // Buscar coincidencia exacta por codigo y etiqueta normalizada
    let match = (this.formasCobro || []).find((f: any) => {
      const lbl = this.normalizeLabel((f?.descripcion_sin ?? f?.nombre ?? f?.label ?? f?.descripcion ?? '').toString());
      return `${f?.codigo_sin}` === sel && lbl === optLabelNorm;
    });
    // Fallback por codigo_sin si no se encontró por etiqueta
    if (!match) match = (this.formasCobro || []).find((f: any) => `${f?.codigo_sin}` === sel);
    const idInterno = match ? `${match.id_forma_cobro}` : '';
    // Asegurar que SIAT reciba el codigo_sin base cuando sea una variante QR
    const baseCodigo = match ? this.getBaseCodigoSinForSiat(match) : sel;
    this.codigoSinBaseSelected = baseCodigo;
    cab.patchValue({ id_forma_cobro: idInterno }, { emitEvent: false });
    // Flag UI: QR según el texto del option seleccionado
    this.qrMetodoSelected = optLabelNorm.includes('QR');
    // Guardar item seleccionado para que el modal herede correctamente la variante
    this.selectedFormaItem = match || null;
    const idCtrl = cab.get('id_forma_cobro');
    idCtrl?.markAsTouched();
    idCtrl?.updateValueAndValidity({ emitEvent: false });
    // Revalidar y limpiar errores si corresponde
    this.clearSoloEfectivoErrorIfMatches();
    // Recalcular opciones para el modal (filtradas por selección actual)
    this.computeModalFormasFromSelection();
    if (this.isQrMetodoSeleccionado()) { this.checkQrPendiente(); }
    this.metodoPagoLocked = true;
    try { cab.get('codigo_sin')?.disable({ emitEvent: false }); } catch {}
  }

  isQrMetodoSeleccionado(): boolean {
    return this.qrMetodoSelected === true;
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

  // Dado un item del catálogo, retorna el codigo_sin "base" para SIAT.
  // Si el label es un QR variante (ej. "QR TRANSFERENCIA BANCARIA"), se mapea
  // al codigo_sin del método base (ej. "TRANSFERENCIA BANCARIA").
  private getBaseCodigoSinForSiat(item: any): string {
    try {
      if (!item) return '';
      const raw = (item?.descripcion_sin ?? item?.nombre ?? item?.label ?? item?.descripcion ?? '').toString();
      const norm = this.normalizeLabel(raw);
      if (!norm.includes('QR')) return `${item?.codigo_sin ?? ''}`;
      // Extraer segmento base luego de quitar el prefijo 'QR '
      const baseSeg = norm.replace(/^QR\s*/, '').split(/[-–—]/)[0].trim();
      if (!baseSeg) return `${item?.codigo_sin ?? ''}`;
      const sameId = (this.formasCobro || []).filter((f: any) => `${f?.id_forma_cobro}`.toUpperCase() === `${item?.id_forma_cobro}`.toUpperCase());
      let baseMatch = sameId.find((f: any) => {
        const n = this.normalizeLabel((f?.descripcion_sin ?? f?.nombre ?? f?.label ?? f?.descripcion ?? '').toString());
        return !n.includes('QR') && (n === baseSeg || n.startsWith(baseSeg) || n.includes(baseSeg));
      });
      if (!baseMatch) {
        // Fallback: buscar en todo el catálogo (independiente del id_forma_cobro)
        baseMatch = (this.formasCobro || []).find((f: any) => {
          const n = this.normalizeLabel((f?.descripcion_sin ?? f?.nombre ?? f?.label ?? f?.descripcion ?? '').toString());
          return !n.includes('QR') && (n === baseSeg || n.startsWith(baseSeg) || n.includes(baseSeg));
        });
      }
      return baseMatch ? `${baseMatch.codigo_sin}` : `${item?.codigo_sin ?? ''}`;
    } catch { return `${item?.codigo_sin ?? ''}`; }
  }

  private findBaseForma(name: string): any | null {
    const target = this.normalizeLabel(name);
    // Preferir entradas "base" (sin guiones) por coincidencia de nombre con scoring
    const prioritized: Record<string, string[]> = {
      'QR': ['QR', 'QR TRANSFERENCIA', 'QR TRANSFERENCIA BANCARIA', 'CODIGO QR'],
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
      // Si se tiene el item exacto previamente seleccionado, usarlo directamente
      if (this.selectedFormaItem) { this.modalFormasCobro = [this.selectedFormaItem]; return; }
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
        if (n.includes('QR')) return 'QR';
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
      try { console.log('[Cobros] computeModalFormasFromSelection parts', { raw, parts, out: out.map(f => ({ id: f?.id_forma_cobro, label: (f?.descripcion_sin ?? f?.nombre ?? '') })) }); } catch {}
      // Si la selección contiene 'QR', forzar que la forma base 'QR' quede primera en la lista
      try {
        const hasQR = this.normalizeLabel(raw).includes('QR');
        if (hasQR) {
          const idxQR = this.modalFormasCobro.findIndex(f => this.normalizeLabel((f?.descripcion_sin ?? f?.nombre ?? '')).includes('QR'));
          if (idxQR > 0) {
            const qrItem = this.modalFormasCobro[idxQR];
            this.modalFormasCobro.splice(idxQR, 1);
            this.modalFormasCobro = [qrItem, ...this.modalFormasCobro];
          }
        }
      } catch {}
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

    console.log('[Cobros] buscarPorCodCetaCabecera xxxssasdd', { cod_ceta, gestion });
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
          // No encontrado: habilitar edición y limpiar razón social en modal y en la página principal
          this.razonSocialEditable = true;
          this.modalIdentidadForm.patchValue({ razon_social: '' }, { emitEvent: false });
          this.identidadForm.patchValue({ razon_social: '' }, { emitEvent: false });
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
    this.mensualidadPU = this.getMensualidadPuForModal();
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
    // Calcular lista de métodos permitidos en el modal según selección actual
    this.computeModalFormasFromSelection();
    this.modalTipo = 'recuperacion';
    try {
      console.log('[OPEN REC] monto', this.resumen?.recuperacion?.monto, this.resumen?.recuperacion_pendiente?.monto);
      const el = document.getElementById('recuperacionModal');
      const bs = (window as any).bootstrap;
      if (el && bs?.Modal) {
        const modal = bs.Modal.getInstance(el) || new bs.Modal(el);
        modal.show();
      }
    } catch {}
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
      // Ignorar filas parciales para este cálculo (solo considerar cuotas completas)
      if (/(\(Parcial\))/i.test(det)) continue;
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
  getNextMensualidadStartCuota(): number {
    // 0) Si hay un override explícito, respételo SIEMPRE (sirve para forzar avance tras completar saldo)
    if (this.startCuotaOverrideValue && this.startCuotaOverrideValue > 0) {
      return this.startCuotaOverrideValue;
    }
    // 1) Si hay una cuota bloqueada por parcial, usarla mientras exista saldo en front
    if (this.lockedMensualidadCuota && this.frontSaldoByCuota[this.lockedMensualidadCuota] !== undefined && this.frontSaldoByCuota[this.lockedMensualidadCuota] > 0) {
      return this.lockedMensualidadCuota;
    }
    // 2) Si la última línea parcial tiene saldo registrado, mantener esa cuota
    const lastParcial = this.getLastMensualidadParcialCuotaInForm();
    if (lastParcial) {
      const saldo = this.frontSaldoByCuota[lastParcial];
      if (saldo !== undefined && saldo > 0) return lastParcial; // mantener misma cuota
      if (saldo === undefined || saldo === 0) return lastParcial + 1; // avanzar una vez cubierto el saldo
    }
    // 3) Caso normal: usar siguiente a la mayor cuota COMPLETA en el detalle o backend next
    const backendNext = Number(this.resumen?.mensualidad_next?.next_cuota?.numero_cuota || 1);
    const inFormMax = this.getMaxMensualidadCuotaInForm(); // ignora parciales
    return Math.max(backendNext, inFormMax + 1);
  }

  private monthName(n: number): string {
    const names = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return names[n] || String(n);
  }

  private getMesNombreByCuotaFromResumen(cuota: number | null | undefined): string | null {
    try {
      const n = Number(cuota || 0);
      if (!n) return null;
      const map = (this.resumen?.mensualidad_meses || []) as Array<any>;
      const hit = map.find(m => Number(m?.numero_cuota || 0) === n);
      if (hit && hit.mes_nombre) return String(hit.mes_nombre);
      // Fallback por gestión si el backend no trajo el mapeo
      const gestion = (this.resumen?.gestion || '').toString();
      const sem = parseInt((gestion || '').split('/')[0] || '0', 10);
      const base = sem === 1 ? [2,3,4,5,6] : sem === 2 ? [7,8,9,10,11] : [];
      const idx = n - 1;
      if (idx >= 0 && idx < base.length) return this.monthName(base[idx]);
      return null;
    } catch { return null; }
  }

  onAddPagosFromModal(payload: any): void {
    try { console.log('[Cobros] onAddPagosFromModal payload', payload); } catch {}
    const hoy = new Date().toISOString().slice(0, 10);
    const pagos = Array.isArray(payload) ? payload : (payload?.pagos || []);
    const headerPatch = Array.isArray(payload) ? null : (payload?.cabecera || null);
    const isMensualidad = this.modalTipo === 'mensualidad';
    const isArrastre = this.modalTipo === 'arrastre';
    const isReincorporacion = this.modalTipo === 'reincorporacion';
    // Enforce un único tipo de documento en el detalle
    const existingDoc = this.getCurrentDocTipoInForm(); // 'F' | 'R' | '' | 'MIXED'
    if (existingDoc === 'MIXED') {
      this.showAlert('El detalle ya contiene FACTURA y RECIBO. Elimine o unifique antes de agregar más.', 'error');
      return;
    }
    const incomingDocs = this.collectDocTiposFromArray(pagos);
    const incomingDoc = this.resolveUniformDocTipo(incomingDocs); // 'F' | 'R' | '' | null (mixto)
    if (incomingDoc === null) {
      this.showAlert('No se puede mezclar FACTURA y RECIBO en el mismo agregado. Seleccione un único tipo.', 'error');
      return;
    }
    if ((existingDoc === 'F' || existingDoc === 'R') && incomingDoc && incomingDoc !== existingDoc) {
      const label = existingDoc === 'F' ? 'FACTURA' : 'RECIBO';
      this.showAlert(`Ya hay líneas con ${label}. Debe ingresar el mismo tipo de documento (${label}).`, 'error');
      return;
    }
    if ((existingDoc === 'F' || existingDoc === 'R') && !incomingDoc) {
      const label = existingDoc === 'F' ? 'FACTURA' : 'RECIBO';
      this.showAlert(`Debe seleccionar tipo de documento ${label} para continuar.`, 'error');
      return;
    }
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
          const lastParcial = this.getLastMensualidadParcialCuotaInForm();
          if (lastParcial && this.frontSaldoByCuota[lastParcial] !== undefined && this.frontSaldoByCuota[lastParcial] > 0) {
            this.lockedMensualidadCuota = lastParcial;
            numeroCuota = lastParcial;
          } else {
            this.lockedMensualidadCuota = null;
            numeroCuota = startCuota + idx;
          }
        }
      } else if (isArrastre) {
        // Para arrastre, respetar el número de cuota provisto por el modal/payload
        const fromPayload = Number(p?.numero_cuota || 0) || null;
        numeroCuota = fromPayload;
      }
      const esParcial = !!p.pago_parcial;
      const mesLabel = this.getMesNombreByCuotaFromResumen(numeroCuota);
      const mesSuffix = mesLabel ? ` (${mesLabel})` : '';
      const baseDetalle = isMensualidad
        ? `Mensualidad - Cuota ${numeroCuota}${mesSuffix}`
        : (isArrastre
            ? `Mensualidad (Arrastre) - Cuota ${numeroCuota ?? ''}${mesSuffix}`.trim()
            : (isReincorporacion
                ? 'Reincorporación'
                : (p.detalle || '')));
      const detalle = esParcial ? `${baseDetalle} (Parcial)` : baseDetalle;

      // Para mensualidad/arrastre: calcular desde PU y descuento
      // Para otros (Reincorporación, Rezagado, etc.): usar monto directo del payload
      const pu = isMensualidad || isArrastre
        ? Number(p.pu_mensualidad ?? this.mensualidadPU ?? 0)
        : Number(p.pu_mensualidad ?? p.monto ?? 0);
      const cant = 1;
      const desc = Number(p.descuento ?? 0) || 0;

      // Calcular monto según el tipo
      const monto = (isMensualidad || isArrastre)
        ? (esParcial
            ? Math.max(0, Number(p.monto || 0))
            : Math.max(0, cant * pu - (isNaN(desc) ? 0 : desc)))
        : Math.max(0, Number(p.monto || 0));
      // Inferir turno desde identidad/resumen
      const turnoVal = (() => {
        let t = ((this.identidadForm.get('turno') as any)?.value || this.resumen?.inscripcion?.turno || this.resumen?.estudiante?.turno || '').toString().trim().toUpperCase();
        if (t === 'M') t = 'MANANA';
        if (t === 'T') t = 'TARDE';
        if (t === 'N') t = 'NOCHE';
        return t.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      })();
      const neto = Math.max(0, pu - (isNaN(desc) ? 0 : desc));
      const saldo = esParcial ? Math.max(0, neto - monto) : 0;
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
        pu_mensualidad: [pu],
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
        es_parcial: [esParcial],
        // campos adicionales para QR
        numero_cuota: [numeroCuota ?? null],
        turno: [turnoVal || null],
        monto_saldo: [saldo || null]
      }));

      // Actualizar bloqueo: si es parcial, bloquear esa cuota; si no es parcial, liberar bloqueo
      if (isMensualidad) {
        if (esParcial && numeroCuota) {
          this.lockedMensualidadCuota = numeroCuota;
          // Guardar saldo restante en frontend y desbloquear si quedó en 0
          this.frontSaldoByCuota[numeroCuota] = saldo;
          if (saldo <= 0) {
            delete this.frontSaldoByCuota[numeroCuota];
            this.lockedMensualidadCuota = null;
            // Avanzar explícitamente a la siguiente cuota para el próximo modal
            this.startCuotaOverrideValue = (numeroCuota || 0) + 1;
          }
          // Mantener override explícito mientras haya saldo
          if (saldo > 0) this.startCuotaOverrideValue = numeroCuota;
        } else if (!esParcial) {
          this.lockedMensualidadCuota = null;
          this.startCuotaOverrideValue = null;
        }
      }
    });
    // Recalcular PU para el próximo modal según saldo guardado o fallback del backend
    if (isMensualidad) {
      const locked = this.lockedMensualidadCuota as number | null;
      if (locked && this.frontSaldoByCuota[locked] !== undefined) {
        this.mensualidadPU = this.frontSaldoByCuota[locked];
      } else {
        // Preferir PU semestral nominal cuando ya no hay saldo bloqueado
        const puSemestral = Number(this.resumen?.totales?.pu_mensual || 0);
        const puNext = Number(this.resumen?.mensualidad_next?.next_cuota?.monto ?? 0);
        this.mensualidadPU = puSemestral > 0 ? puSemestral : puNext;
      }
    }
    // Aplicar cabecera si el modal la envió (p.e. id_cuentas_bancarias para TARJETA)
    if (headerPatch && typeof headerPatch === 'object') {
      (this.batchForm.get('cabecera') as FormGroup).patchValue(headerPatch, { emitEvent: false });
    }
    try {
      const debug = (this.pagos.controls || []).map((ctrl, i) => ({
        i,
        id_forma_cobro: (ctrl as FormGroup).get('id_forma_cobro')?.value,
        monto: (ctrl as FormGroup).get('monto')?.value,
        detalle: (ctrl as FormGroup).get('detalle')?.value,
      }));
      console.log('[Cobros] pagos after add', debug);
    } catch {}
    this.showAlert('Pago(s) añadidos al lote', 'success');
  }

  submitBatch(): void {
    console.log('HOIla');
    if (this.loading) {
      console.warn('[Cobros] submitBatch() ignored because loading=true');
      return;
    }

    console.log('[Cobros] submitBatch() called', {
      loading: this.loading,
      formValid: this.batchForm.valid,
      pagosLength: this.pagos.length,
      qrPanelStatus: this.qrPanelStatus,
      cabecera: (this.batchForm.get('cabecera') as FormGroup)?.getRawValue?.() || null
    });
    console.log('HOIla 2');
    if (this.loading) {
      console.warn('[Cobros] submitBatch() ignored because loading=true');
      return;
    }
    const cab = this.batchForm.get('cabecera') as FormGroup;
    // 1) cod_ceta: desde resumen o searchForm si falta

    console.log('[Cobros] submitBatch() pre-patch cabecera', cab?.getRawValue?.() || null);
    console.log('LOS PAGOS QUE LLEGAN SON:', this.pagos.getRawValue() || null);
    console.log('EL RESUMEN ES:', this.resumen || null);
    try {
      const currentCod = cab?.get('cod_ceta')?.value;
      if (!currentCod) {
        const codFromResumen = this.resumen?.estudiante?.cod_ceta || '';
        const codFromSearch = (this.searchForm.get('cod_ceta')?.value || '').toString().trim();
        const codFinal = codFromResumen || codFromSearch;
        if (codFinal) cab.patchValue({ cod_ceta: codFinal }, { emitEvent: false });
      }
    } catch {}
    console.log('HOIla 3');
    // 1.1) cod_pensum / tipo_inscripcion / gestion desde resumen.inscripcion si faltan
    try {
      const ins = (this.resumen as any)?.inscripcion || (this.resumen as any)?.inscripciones?.[0] || null;
      const patch: any = {};
      if (!cab?.get('cod_pensum')?.value && ins?.cod_pensum) patch.cod_pensum = String(ins.cod_pensum);
      if (!cab?.get('tipo_inscripcion')?.value && ins?.tipo_inscripcion) patch.tipo_inscripcion = String(ins.tipo_inscripcion);
      if (!cab?.get('gestion')?.value && (this.resumen as any)?.gestion) patch.gestion = String((this.resumen as any).gestion);
      if (Object.keys(patch).length) cab.patchValue(patch, { emitEvent: false });
    } catch {}
    // 1.2) Si hay filas de ARRASTRE en el detalle, forzar cabecera a usar la inscripción ARRASTRE
    try {
      const hasArrRows = (this.pagos.controls || []).some(ctrl => {
        const d = ((ctrl as FormGroup).get('detalle')?.value || '').toString().toUpperCase();
        return d.includes('ARRASTRE');
      });
      if (hasArrRows) {
        const arrObj: any = (this.resumen as any)?.arrastre?.inscripcion || null;
        const patch: any = { tipo_inscripcion: 'ARRASTRE' };
        if (arrObj?.cod_pensum) patch.cod_pensum = String(arrObj.cod_pensum);
        if (arrObj?.gestion) patch.gestion = String(arrObj.gestion);
        (this.batchForm.get('cabecera') as FormGroup).patchValue(patch, { emitEvent: false });
        // Guardar en memoria local para el payload (cod_inscrip no existe en form cabecera)
        (this as any)._arrInsPayload = arrObj;
      }
    } catch {}
    // 2) id_forma_cobro: tomar del modal si cabecera está vacío
    try {
      const currentForma = cab?.get('id_forma_cobro')?.value;
      if (!currentForma) {
        const metodo = this.mensualidadModalForm.get('metodo_pago')?.value;
        if (metodo) cab.patchValue({ id_forma_cobro: String(metodo) }, { emitEvent: false });
      }
    } catch {}
    console.log('HOIla 4');
    // 3) id_usuario: desde AuthService o localStorage current_user
    try {
      const currentUser = this.auth.getCurrentUser();
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
    console.log('HOIla 5');
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
    console.log('HOIla 6');
    // Forzar visualización de errores de validación en el formulario
    try { this.batchForm.markAllAsTouched(); } catch {}
    if (!this.batchForm.valid || this.pagos.length === 0) {
      console.warn('[Cobros] submitBatch() invalid form or empty pagos', {
        formValid: this.batchForm.valid,
        pagosLength: this.pagos.length,
        formErrors: this.getFormErrors(this.batchForm)
      });
      this.showAlert('Complete los datos y agregue al menos un pago', 'warning');
      return;
    }
    console.log('HOIla 7');
    const hasQrRows = (this.pagos.controls || []).some(ctrl => this.isFormaIdQR((ctrl as FormGroup).get('id_forma_cobro')?.value));
    if (hasQrRows && this.qrPanelStatus !== 'completado') {
      const cod = (this.batchForm.get('cabecera.cod_ceta') as any)?.value || '';
      const hasWaitingFlag = (() => { try { return !!(cod && sessionStorage.getItem(`qr_session:${cod}:waiting_saved`) === '1'); } catch { return false; } })();
      if (this.qrSavedWaiting && hasWaitingFlag) {
        this.showAlert('Lote guardado en espera. Consulte con administración para la impresión de Recibo/Factura seleccionada cuando el pago QR sea confirmado.', 'success', 10000);
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch {}
        // Mostrar confirmación para refrescar sólo si el usuario lo aprueba
        this.openQrSavedWaitingConfirmModal();
      } else {
        this.showAlert('Hay pagos QR pendientes. Espere a que el QR se complete o use "Guardar en espera (QR)".', 'warning');
      }
      return;
    }
    console.log('HOIla 8');
    // Enviar todas las filas (incluida la QR) cuando el estado QR es 'completado';
    // el backend ya no inserta desde callback
    const baseCtrls = (this.pagos.controls || []);
    if (baseCtrls.length === 0) {
      this.showAlert('El pago QR se registrará automáticamente. No hay items para guardar.', 'warning');
      return;
    }
    this.loading = true;
    const { cabecera } = this.batchForm.value as any;
    // Asegurar codigo_sin base para SIAT (QR variante -> base)
    const siatCodigoSin = this.codigoSinBaseSelected || (cabecera?.codigo_sin || '');
    // Mapear pagos para enviar solo con 'monto' calculado y fallbacks de nro/fecha
    const hoy = new Date().toISOString().slice(0, 10);
    const pagosRaw = (baseCtrls || []).map((ctrl, idx) => {
      const raw = (ctrl as FormGroup).getRawValue() as any;
      const subtotal = this.calcRowSubtotal(idx);
      const fecha = raw.fecha_cobro || hoy;
      const item: any = { ...raw, fecha_cobro: fecha, monto: subtotal, nro_cobro: Number(raw?.nro_cobro || 0) };
      if (!item.nro_cobro || item.nro_cobro <= 0) {
        item.nro_cobro = this.getNextCobroNro();
      }
      return item;
    });
    console.log('HOIla 9');
    // Normalizar tipo_documento y medio_doc para todos los items
    const pagos = pagosRaw.map((it: any) => {
      const tipo = this.normalizeDocFromPayload(it);
      const medio = (() => {
        const md = ((it?.medio_doc || '') + '').trim().toUpperCase();
        const comp = ((it?.computarizada || '') + '').trim().toUpperCase();
        if (md === 'M' || comp === 'MANUAL') return 'M';
        if (md === 'C' || comp === 'COMPUTARIZADA') return 'C';
        return 'C';
      })();
      return { ...it, tipo_documento: tipo || 'R', medio_doc: medio || 'C' };
    });
    const payload = {
      ...cabecera,
      codigo_sin: siatCodigoSin,
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
    // Inyectar cod_inscrip de ARRASTRE si corresponde
    try {
      const hasArrRows = (this.pagos.controls || []).some(ctrl => {
        const d = ((ctrl as FormGroup).get('detalle')?.value || '').toString().toUpperCase();
        return d.includes('ARRASTRE');
      });
      if (hasArrRows) {
        const mem = (this as any)._arrInsPayload;
        if (mem?.cod_inscrip) (payload as any).cod_inscrip = Number(mem.cod_inscrip);
        (payload as any).tipo_inscripcion = 'ARRASTRE';
      }
    } catch {}
    try {
      const uni = String((payload as any).id_forma_cobro || '');
      if (uni) {
        (payload as any).pagos = ((payload as any).pagos || []).map((p: any) => ({ ...p, id_forma_cobro: uni }));
      }
    } catch {}
    console.log('HOIla 10');
    // Forzar bandera emitir_online si hay al menos una Factura Computarizada
    try {
      const shouldEmitOnline = pagos.some((p: any) => (p?.tipo_documento === 'F') && (p?.medio_doc === 'C'));
      if (shouldEmitOnline) (payload as any).emitir_online = true;
    } catch {}
    this.cobrosService.batchStore(payload).subscribe({
      next: (res) => {
        if (res.success) {
          try {
            const items = (res?.data?.items || []) as Array<any>;
            // Aviso si alguna factura computarizada fue rechazada por SIN
            let hasFacturaError = false;
            try {
              const rechazadas = items.filter((it: any) => (it?.tipo_documento === 'F') && (it?.medio_doc === 'C') && (it?.estado_factura === 'RECHAZADA'));
              if (rechazadas.length > 0) {
                hasFacturaError = true;
                const det = rechazadas.map((r: any) => `#${r?.nro_factura || '?'}${r?.mensaje ? ' - ' + r.mensaje : ''}`).join(' | ');
                this.showAlert(`⚠️ Ups! Hubo un problema con la facturación.\n\nEl cobro se registró correctamente pero la factura fue rechazada por el SIN.\n\nPor favor revise más tarde o notifique al administrador.\n\nDetalles: ${det}`, 'warning', 15000);
              }
            } catch {}
            // Construir resumen de éxito ANTES de cualquier limpieza
            this.successSummary = this.buildSuccessSummary(items);
            // Mostrar modal de éxito
            this.openSuccessModal();
            const seen = new Set<string>();
            for (const it of items) {
              // Recibo computarizado
              if ((it?.tipo_documento === 'R') && (it?.medio_doc === 'C') && it?.nro_recibo) {
                const fecha = it?.cobro?.fecha_cobro || hoy;
                const anio = new Date(fecha).getFullYear();
                this.downloadReciboPdfWithFallback(anio, it.nro_recibo);
              }
              // Factura computarizada - NO descargar si fue rechazada
              if ((it?.tipo_documento === 'F') && (it?.medio_doc === 'C') && it?.nro_factura) {
                // Verificar si la factura fue rechazada
                if (it?.estado_factura === 'RECHAZADA' || it?.factura_error) {
                  console.warn('Factura rechazada, no se descarga PDF:', it);
                  continue; // Saltar descarga de PDF
                }
                const fechaF = it?.cobro?.fecha_cobro || hoy;
                const anioF = new Date(fechaF).getFullYear();
                const key = `${anioF}:${it.nro_factura}`;
                if (seen.has(key)) continue;
                seen.add(key);
                this.downloadFacturaPdfWithFallback(anioF, it.nro_factura, it);
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
    console.log('HOIla 11');
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

  // P/U mostrado en la tabla: para pagos parciales con descuento, mostrar
  // monto_parcial + descuento_prorrateado (sin regla de 3)
  // en otros casos, mostrar pu_mensualidad normal.
  getRowDisplayPU(i: number): number {
    try {
      const g = this.pagos.at(i) as FormGroup;
      if (!g) return 0;
      const esParcial = !!g.get('es_parcial')?.value;
      const puEff = Number(g.get('pu_mensualidad')?.value || 0);
      if (!esParcial) return puEff;

      // Para pagos parciales: P/U = monto_parcial + descuento_prorrateado
      const montoParcial = Number(g.get('monto')?.value || 0);
      const descuentoProrrateado = this.calcularDescuentoProrrateadoParaFila(i);

      // Usar 4 decimales internamente, redondear al final
      const resultado = Math.round((montoParcial + descuentoProrrateado) * 10000) / 10000;
      return resultado;
    } catch {
      return 0;
    }
  }

  // Calcular descuento prorrateado para una fila específica (4 decimales)
  calcularDescuentoProrrateadoParaFila(i: number): number {
    try {
      const g = this.pagos.at(i) as FormGroup;
      if (!g) return 0;

      const esParcial = !!g.get('es_parcial')?.value;
      if (!esParcial) return 0;

      const montoParcial = Number(g.get('monto')?.value || 0);
      if (montoParcial <= 0) return 0;

      const numeroCuota = Number(g.get('numero_cuota')?.value || 0) || null;
      const idAsign = Number(g.get('id_asignacion_costo')?.value || 0) || null;

      // Buscar la cuota en el resumen
      const asignList: any[] = Array.isArray(this.resumen?.asignaciones)
        ? this.resumen!.asignaciones
        : (Array.isArray(this.resumen?.asignacion_costos?.items) ? this.resumen!.asignacion_costos.items : []);

      let cuota: any = null;
      if (numeroCuota) {
        cuota = asignList.find((a: any) => Number(a?.numero_cuota || a?.numero || 0) === Number(numeroCuota));
      } else if (idAsign) {
        cuota = asignList.find((a: any) => Number(a?.id_asignacion_costo || 0) === Number(idAsign));
      }

      if (!cuota) return 0;

      const descuentoPago = Number(cuota?.descuento || 0);
      if (descuentoPago <= 0) return 0;

      const deudaPagada = Number(cuota?.monto_pagado || 0);
      const totalDebePagar = Number(cuota?.total_debe_pagar || 0);
      const descuentoAplicado = Number(cuota?.descuento_aplicado || 0);

      if (totalDebePagar <= 0) return 0;

      // Fórmula: descuento_pago * ((monto_pago_parcial + deuda_pagada) / total_debe_pagar) - descuento_aplicado
      const descuentoProrrateado = (descuentoPago * ((montoParcial + deudaPagada) / totalDebePagar)) - descuentoAplicado;

      // Redondear a 4 decimales
      return Math.round(Math.max(0, descuentoProrrateado) * 10000) / 10000;
    } catch (error) {
      console.error('[Cobros] Error al calcular descuento prorrateado para fila:', error);
      return 0;
    }
  }

  // Descuento mostrado en la tabla: para pagos parciales, mostrar descuento_prorrateado
  // en otros casos, mostrar el descuento normal de la fila.
  getRowDisplayDescuento(i: number): number {
    try {
      const g = this.pagos.at(i) as FormGroup;
      if (!g) return 0;
      const esParcial = !!g.get('es_parcial')?.value;
      if (!esParcial) {
        return Number(g.get('descuento')?.value || 0);
      }

      // Para pagos parciales: retornar descuento prorrateado
      return this.calcularDescuentoProrrateadoParaFila(i);
    } catch {
      return 0;
    }
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

  get totalPagadoMensualidad(): number {
    try {
      const gestion = this.getCurrentGestion();
      const montoSemestral = this.toNumber((this.resumen?.totales?.monto_semestral ?? 0));

      // 1) Si tenemos 'asignaciones' pero no 'asignacion_costos', usar cálculo exacto solicitado (NORMAL + ARRASTRE)
      const hasAsignaciones = Array.isArray(this.resumen?.asignaciones) && this.resumen!.asignaciones.length > 0;
      const noAsignCostos = !this.resumen?.asignacion_costos;
      if (hasAsignaciones && noAsignCostos) {
        const exact = this.computeTotalPagadoExact();
        return Math.min(exact, montoSemestral);
      }

      // 2) Fuente del backend si no aplica el cálculo exacto anterior
      const mensualidadTotal = this.toNumber(this.resumen?.cobros?.mensualidad?.total ?? 0);
      if (mensualidadTotal > 0) {
        return Math.min(mensualidadTotal, montoSemestral);
      }

      // Fuente AUTORITATIVA: tabla de asignación de costos (lo que se ve en la UI)
      const asignCostos: any[] = Array.isArray(this.resumen?.asignacion_costos?.items) ? this.resumen!.asignacion_costos.items : [];
      const allowedIns = this.getAllowedInscripcionIds();
      const isGestion = (it: any) => {
        const g = `${it?.gestion ?? it?.gestion_cuota ?? it?.gestion_asignacion ?? ''}`;
        // Si el item no trae gestión, no excluirlo; si trae, exigir coincidencia cuando se consultó gestión
        if (gestion) return !g || `${g}` === `${gestion}`;
        return true;
      };
      const isInscripcion = (it: any) => {
        const id = `${it?.id_inscripcion ?? it?.inscripcion_id ?? it?.cod_inscrip ?? ''}`;
        // Si el item no trae id de inscripción, no filtrar por inscripción
        if (!id) return true;
        // si tenemos lista de inscripciones válidas, exigir pertenencia
        return allowedIns.size ? allowedIns.has(id) : true;
      };
      const isEstadoPagado = (it: any) => {
        const st = (it?.estado_pago || '').toString().trim().toUpperCase();
        return st === 'COBRADO' || st === 'PARCIAL';
      };
      let suma = 0;
      if (asignCostos.length) {
        // Sumar exactamente como en la grilla: monto_pagado de filas de la gestión, sin otras fuentes
        for (const it of asignCostos) {
          if (!isGestion(it) || !isInscripcion(it) || !isEstadoPagado(it)) continue;
          suma += this.toNumber(it?.monto_pagado);
        }
        // Complementar con cobros.mensualidad por si falta ARRASTRE en asignaciones
        const sumCobros = this.sumCobrosMensualidadByGestionInscripciones();
        const base = Math.max(suma, sumCobros);
        return Math.min(base, montoSemestral);
      }

      // Fallback: usar 'asignaciones' si no llegó asignacion_costos
      const asignaciones: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen!.asignaciones : [];
      if (asignaciones.length) {
        for (const it of asignaciones) {
          if (!isGestion(it) || !isEstadoPagado(it)) continue; // no exigimos id de inscripción aquí
          suma += this.toNumber(it?.monto_pagado);
        }
        // Complementar con cobros.mensualidad por si falta ARRASTRE
        const sumCobros = this.sumCobrosMensualidadByGestionInscripciones();
        const base = Math.max(suma, sumCobros);
        return Math.min(base, montoSemestral);
      }

      // Sin datos
      return 0;
    } catch { return 0; }
  }

  get saldoMensualidadCalc(): number {
    const sem = this.toNumber((this.resumen?.totales?.monto_semestral ?? 0));
    const pag = this.totalPagadoMensualidad;
    const s = sem - pag;
    return s > 0 ? s : 0;
  }

  public getCurrentGestion(): string {
    try {
      const cab = (this.batchForm.get('cabecera') as FormGroup);
      const fromResumen = (this.resumen as any)?.gestion || (this.resumen as any)?.inscripcion?.gestion || (this.resumen as any)?.inscripciones?.[0]?.gestion || '';
      const fromCab = cab?.get('gestion')?.value || '';
      const fromSearch = (this.searchForm.get('gestion')?.value || '').toString();
      return `${fromCab || fromResumen || fromSearch || ''}`;
    } catch { return ''; }
  }

  private getAllowedInscripcionIds(): Set<string> {
    const out = new Set<string>();
    try {
      const gestion = this.getCurrentGestion();
      const ins = (this.resumen as any)?.inscripcion;
      const arr = (this.resumen as any)?.arrastre;
      const list = (this.resumen as any)?.inscripciones; // potencialmente varias inscripciones
      const push = (v: any) => { const s = (v === null || v === undefined) ? '' : `${v}`; if (s) out.add(s); };
      const matchGestion = (g: any) => {
        const gx = (g === null || g === undefined) ? '' : `${g}`;
        if (!gestion) return true;
        return gx === `${gestion}`;
      };
      if (ins && matchGestion((ins as any).gestion)) push((ins as any).id_inscripcion || (ins as any).id || (ins as any).cod_inscrip);
      if (arr && matchGestion((arr as any).gestion)) push((arr as any).id_inscripcion || (arr as any).id || (arr as any).cod_inscrip);
      if (Array.isArray(list)) {
        for (const it of list) {
          if (!matchGestion(it?.gestion)) continue;
          push(it?.id_inscripcion || it?.id || it?.cod_inscrip);
        }
      }
    } catch {}
    return out;
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
    // Monto/PU de arrastre debe ser NETO (preferir datos de arrastre.asignacion_costos/asignaciones_arrastre)
    let monto = 0;
    try {
      const numero = Number((next as any)?.numero_cuota || 0);
      const ida = Number((next as any)?.id_asignacion_costo || 0);
      const idt = Number((next as any)?.id_cuota_template || 0);
      const acItems: any[] = Array.isArray(this.resumen?.arrastre?.asignacion_costos?.items)
        ? this.resumen!.arrastre.asignacion_costos.items : [];
      let hit: any = null;
      if (acItems.length) hit = acItems.find((a: any) => {
        const n = Number(a?.numero_cuota || a?.numero || 0);
        const _ida = Number(a?.id_asignacion_costo || 0);
        const _idt = Number(a?.id_cuota_template || 0);
        return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
      }) || null;
      if (!hit) {
        const arrAsigs: any[] = Array.isArray(this.resumen?.arrastre?.asignaciones_arrastre)
          ? this.resumen!.arrastre.asignaciones_arrastre : [];
        hit = arrAsigs.find((a: any) => {
          const n = Number(a?.numero_cuota || 0);
          const _ida = Number(a?.id_asignacion_costo || 0);
          const _idt = Number(a?.id_cuota_template || 0);
          return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
        }) || null;
      }
      if (!hit) {
        const rootArr: any[] = Array.isArray((this.resumen as any)?.asignaciones_arrastre)
          ? (this.resumen as any).asignaciones_arrastre : [];
        hit = rootArr.find((a: any) => {
          const n = Number(a?.numero_cuota || 0);
          const _ida = Number(a?.id_asignacion_costo || 0);
          const _idt = Number(a?.id_cuota_template || 0);
          return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
        }) || null;
      }
      if (hit) {
        const bruto = this.toNumber((hit as any)?.monto);
        const desc = this.toNumber((hit as any)?.descuento);
        const neto = this.toNumber((hit as any)?.monto_neto);
        monto = neto > 0 ? neto : Math.max(0, bruto - desc);
      }
      if (!(monto > 0)) {
        const arrObj: any = (this.resumen as any)?.arrastre || null;
        if (arrObj) {
          const brutoArr = this.toNumber(arrObj?.monto);
          const descArr = this.toNumber(arrObj?.descuento);
          const netoArr = this.toNumber(arrObj?.monto_neto);
          if (netoArr > 0) monto = netoArr; else if (brutoArr > 0 && descArr > 0) monto = Math.max(0, brutoArr - descArr);
        }
      }
      if (!(monto > 0)) {
        const brutoNext = this.toNumber((next as any)?.monto);
        const descNext = this.toNumber((next as any)?.descuento);
        const netoNext = this.toNumber((next as any)?.monto_neto);
        monto = netoNext > 0 ? netoNext : Math.max(0, brutoNext - descNext);
      }
    } catch { monto = Number((next as any)?.monto || 0); }
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

  private getCurrentDocTipoInForm(): 'F' | 'R' | '' | 'MIXED' {
    try {
      let hasF = false, hasR = false;
      for (let i = 0; i < (this.pagos?.length || 0); i++) {
        const g = this.pagos.at(i) as FormGroup;
        const v = (g?.get('tipo_documento')?.value || '').toString().trim().toUpperCase();
        if (v === 'F') hasF = true; else if (v === 'R') hasR = true;
        if (hasF && hasR) return 'MIXED';
      }
      if (hasF) return 'F';
      if (hasR) return 'R';
      return '';
    } catch { return ''; }
  }

  private normalizeDocFromPayload(p: any): 'F' | 'R' | '' {
    try {
      const raw = ((p?.tipo_documento || p?.comprobante || '') + '').trim().toUpperCase();
      if (raw === 'F' || raw === 'FACTURA') return 'F';
      if (raw === 'R' || raw === 'RECIBO') return 'R';
      return '';
    } catch { return ''; }
  }

  private collectDocTiposFromArray(arr: any[]): Array<'F' | 'R' | ''> {
    try { return (arr || []).map(p => this.normalizeDocFromPayload(p)); } catch { return []; }
  }

  private resolveUniformDocTipo(docs: Array<'F' | 'R' | ''>): 'F' | 'R' | '' | null {
    try {
      const hasF = docs.some(d => d === 'F');
      const hasR = docs.some(d => d === 'R');
      if (hasF && hasR) return null;
      if (hasF) return 'F';
      if (hasR) return 'R';
      return '';
    } catch { return null; }
  }

  private cargarParametrosDescuentoInstitucional(): void {
    this.peService.getAll().subscribe({
      next: (res) => {
        if (res.success && res.data) {
          const params = res.data;
          const fechaParam = params.find((p: any) => {
            const id = Number(p?.id_parametro_economico || 0);
            const nombre = (p?.nombre || '').toString().toLowerCase().trim();
            return id === 2 || nombre === 'descuento_semestre_completo_fecha';
          });
          this.descuentoInstitucionalFechaLimite = fechaParam?.valor || null;
          console.log('[Cobros] Parámetro descuento institucional:', {
            encontrado: !!fechaParam,
            fecha: this.descuentoInstitucionalFechaLimite,
            mostrarBoton: this.mostrarBotonDescuento
          });
        }
      },
      error: () => {
        this.descuentoInstitucionalFechaLimite = null;
      }
    });
  }

  get mostrarBotonDescuento(): boolean {
    if (!this.descuentoInstitucionalFechaLimite) {
      console.log('[Cobros] No hay fecha límite configurada, mostrando botón');
      return true;
    }
    try {
      const fechaLimite = new Date(this.descuentoInstitucionalFechaLimite);
      const hoy = new Date();
      hoy.setHours(0, 0, 0, 0);
      fechaLimite.setHours(0, 0, 0, 0);
      const mostrar = hoy <= fechaLimite;
      console.log('[Cobros] Comparación de fechas:', {
        fechaLimiteStr: this.descuentoInstitucionalFechaLimite,
        fechaLimite: fechaLimite.toISOString(),
        hoy: hoy.toISOString(),
        mostrar,
        diferenciaDias: Math.floor((fechaLimite.getTime() - hoy.getTime()) / (1000 * 60 * 60 * 24))
      });
      return mostrar;
    } catch (error) {
      console.error('[Cobros] Error al comparar fechas:', error);
      return true;
    }
  }

  private showAlert(message: string, type: 'success' | 'error' | 'warning', durationMs: number = 4000): void {
    this.alertMessage = message;
    this.alertType = type;
    if (durationMs > 0) {
      setTimeout(() => (this.alertMessage = ''), durationMs);
    }
  }
}
