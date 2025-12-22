import { Component, EventEmitter, Input, OnChanges, OnInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { ClickLockDirective } from '../../../../directives/click-lock.directive';

@Component({
  selector: 'app-mensualidad-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, ClickLockDirective],
  templateUrl: './mensualidad-modal.component.html',
  styleUrls: ['./mensualidad-modal.component.scss']
})
export class MensualidadModalComponent implements OnInit, OnChanges {
  @Input() resumen: any = null;
  @Input() formasCobro: any[] = [];
  @Input() cuentasBancarias: any[] = [];
  @Input() tipo: 'mensualidad' | 'rezagado' | 'recuperacion' | 'arrastre' | 'reincorporacion' = 'mensualidad';
  // Nota: también soporta 'reincorporacion' como tipo adicional
  @Input() pendientes = 0;
  @Input() pu = 0; // precio unitario de mensualidad
  @Input() baseNro = 1; // nro_cobro inicial sugerido
  @Input() defaultMetodoPago: string = '';
  @Input() startCuotaOverride: number | null = null;
  @Input() frontSaldos: Record<number, number> = {};

  @Output() addPagos = new EventEmitter<any>();

  form: FormGroup;
  modalAlertMessage = '';
  modalAlertType: 'success' | 'error' | 'warning' = 'warning';

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      metodo_pago: ['',[Validators.required]],
      cantidad: [1, [Validators.min(1)]],
      costo_total: [{ value: 0, disabled: true }],
      descuento: [{ value: 0, disabled: true }],
      observaciones: [''],
      comprobante: ['RECIBO', [Validators.required]], // FACTURA | RECIBO (siempre seleccionado)
      nro_factura: [''],
      nro_recibo: [''],
      computarizada: ['COMPUTARIZADA'], // COMPUTARIZADA | MANUAL
      pago_parcial: [false],
      monto_parcial: [{ value: 0, disabled: true }],
      cobro_total_semestre: [false],
      // Para rezagado / recuperación
      rezagado: [false],
      recuperacion: [false],
      monto_manual: [0, [Validators.min(0)]], // usado cuando tipo != mensualidad
      fecha_cobro: [new Date().toISOString().slice(0, 10), Validators.required],
      // Campos para TARJETA / DEPÓSITO
      id_cuentas_bancarias: [''],
      banco_origen: [''],
      tarjeta_first4: [''],
      tarjeta_last4: [''],
      fecha_deposito: [''],
      nro_deposito: ['']
    });
  }

  // Obtiene el monto NETO de una cuota (monto - descuento) desde el resumen por número de cuota
  private getCuotaNetoByNumero(numeroCuota: number): number {
    try {
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(numeroCuota));
      if (!hit) return Number(this.pu || 0);
      const bruto = this.toNumberLoose(hit?.monto);
      const desc = this.toNumberLoose(hit?.descuento);
      const neto = (hit?.monto_neto !== undefined && hit?.monto_neto !== null) ? this.toNumberLoose(hit?.monto_neto) : Math.max(0, bruto - desc);
      return neto;
    } catch { return Number(this.pu || 0); }
  }

  // Obtiene el restante actual para una cuota por número (considera saldos frontales si existen)
  private getCuotaRestanteByNumero(numeroCuota: number): number {
    try {
      const list = this.getOrderedCuotasRestantes();
      const hit = list.find(it => Number(it.numero) === Number(numeroCuota));
      if (hit && hit.restante !== undefined) return Math.max(0, Number(hit.restante || 0));
      // Si no aparece en la lista (posiblemente ya está saldada), restante = 0
      return 0;
    } catch { return 0; }
  }

  // PU efectivo a mostrar para mensualidad: si hay override de cuota inicial, usar restante de esa cuota; si no, usar input pu
  get puDisplay(): number {
    try {
      if (this.tipo !== 'mensualidad') return Number(this.pu || 0);
      const start = this.getStartCuotaFromResumen();
      const list = this.getOrderedCuotasRestantes();
      const hit = list.find(it => Number(it.numero) === Number(start));
      if (hit && hit.restante !== undefined) return Number(hit.restante || 0);
      return Number(this.pu || 0);
    } catch { return Number(this.pu || 0); }
  }

  // Máximo permitido para pago parcial según el PU efectivo
  private getParcialMax(): number {
    const max = this.puDisplay;
    return (isNaN(max) || max <= 0) ? Number.MAX_SAFE_INTEGER : max;
  }

  // Verifica si el bloque de tarjeta está completo para habilitar el resto del formulario
  isCardBlockValid(): boolean {
    if (!this.isTarjeta) return true;
    const controls = [
      'banco_origen',
      'tarjeta_first4',
      'tarjeta_last4',
      'id_cuentas_bancarias',
      'fecha_deposito',
      'nro_deposito',
    ];
    for (const name of controls) {
      const c = this.form.get(name);
      if (!c) return false;
      c.updateValueAndValidity({ emitEvent: false });
      if (!c.valid) return false;
      const v = (c.value ?? '').toString().trim();
      if (!v) return false;
      if ((name === 'tarjeta_first4' || name === 'tarjeta_last4') && !/^\d{4}$/.test(v)) return false;
    }
    return true;
  }

  // Indica si el método seleccionado corresponde a pago con QR
  get isQR(): boolean {
    const f = this.getSelectedForma();
    const code = this.getSelectedCodigoSin();
    if (code !== null) {
      // No existe código estándar de QR en SIN; usar fallback textual
    }
    const match = this.getSelectedForma();
    const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre.includes('QR');
  }

  // Indica si el método seleccionado corresponde a TRANSFERENCIA
  get isTransferencia(): boolean {
    // Si es QR, no considerarlo transferencia para efectos de UI/validaciones
    if (this.isQR) return false;
    const f = this.getSelectedForma();
    const code = this.getSelectedCodigoSin();
    if (code !== null) {
      if ([5].includes(code)) return true; // 5 ~ Transferencia bancaria (usual en SIN)
    }
    const match = this.getSelectedForma();
    const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre.includes('TRANSFER');
  }

  // Indica si el método seleccionado es OTRO (id_forma_cobro = 'O')
  get isOtro(): boolean {
    const f = this.getSelectedForma();
    const raw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
    const id = (f?.id_forma_cobro ?? '').toString().trim().toUpperCase();
    // Tratar explícitamente VALES por id_forma_cobro
    if (id === 'V') return true;
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    if (!nombre) return false;
    if (nombre.includes('TRANSFER') || nombre.includes('TARJETA') || nombre.includes('CHEQUE') || nombre.includes('DEPOSITO') || nombre.includes('QR')) return false;
    return nombre.includes('OTRO') || nombre.includes('VALES') || nombre.includes('PAGO POSTERIOR');
  }

  // Controla visibilidad del bloque bancario (Cheque/Depósito/Transferencia/QR)
  get showBancarioBlock(): boolean {
    if (this.isOtro) return false;
    if (this.isQR) return false; // QR no muestra bloque bancario
    // Para TARJETA ya existe un bloque específico arriba; evitar duplicar campos
    return this.isCheque || this.isDeposito || this.isTransferencia;
  }

  ngOnInit(): void {
    this.recalcTotal();
    // Recalcular total al cambiar cantidad, descuento o monto_manual
    this.form.get('cantidad')?.valueChanges.subscribe(() => this.recalcTotal());
    this.form.get('monto_manual')?.valueChanges.subscribe(() => this.recalcTotal());
    this.form.get('monto_parcial')?.valueChanges.subscribe(() => this.recalcTotal());
    // Cambios de método de pago para activar validadores de TARJETA
    this.form.get('metodo_pago')?.valueChanges.subscribe(() => this.updateTarjetaValidators());
    this.updateTarjetaValidators();

    // Alternar validadores/estado para pago parcial
    this.form.get('pago_parcial')?.valueChanges.subscribe((on: boolean) => {
      // Pago parcial permitido en 'mensualidad' y 'reincorporacion'
      if (this.tipo !== 'mensualidad' && this.tipo !== 'reincorporacion') {
        if (on) this.form.get('pago_parcial')?.setValue(false, { emitEvent: false });
        // Asegurar estado limpio
        this.form.get('cantidad')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.clearValidators();
        this.form.get('monto_parcial')?.disable({ emitEvent: false });
        this.form.get('cantidad')?.updateValueAndValidity({ emitEvent: false });
        this.form.get('monto_parcial')?.updateValueAndValidity({ emitEvent: false });
        this.recalcTotal();
        return;
      }
      if (on) {
        // bloquear cantidad a 1 y habilitar monto_parcial
        this.form.get('cantidad')?.setValue(1, { emitEvent: false });
        this.form.get('cantidad')?.disable({ emitEvent: false });
        this.form.get('monto_parcial')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(Number(this.puDisplay || Number.MAX_SAFE_INTEGER))]);
        // Prefijar el monto parcial con el PU efectivo (ajustado por saldo y cuota inicial)
        this.form.get('monto_parcial')?.setValue(this.puDisplay || 0, { emitEvent: false });
      } else {
        // restaurar cantidad y deshabilitar monto_parcial
        this.form.get('cantidad')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.clearValidators();
        this.form.get('monto_parcial')?.disable({ emitEvent: false });
      }
      this.form.get('cantidad')?.updateValueAndValidity({ emitEvent: false });
      this.form.get('monto_parcial')?.updateValueAndValidity({ emitEvent: false });
      this.recalcTotal();
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['pendientes'] || changes['pu'] || changes['tipo'] || changes['resumen'] || changes['startCuotaOverride']) {
      // Si cambia a un tipo distinto de mensualidad, forzar pago_parcial=false
      if (changes['tipo'] && this.tipo !== 'mensualidad') {
        this.form.patchValue({ pago_parcial: false }, { emitEvent: false });
      }
      this.configureByTipo();
      this.recalcTotal();
      // Actualizar campo de descuento mostrado (sólo informativo) para la próxima cuota
      try {
        const start = this.getStartCuotaFromResumen();
        const neto = this.getCuotaNetoByNumero(start);
        const restante = this.getCuotaRestanteByNumero(start);
        const d = this.getDescuentoForCuota(start);
        // Si la cuota está en estado PARCIAL (restante < neto), el "descuento" ya fue considerado y no aplica nuevamente al saldo
        const showDesc = (restante < neto) ? 0 : d;
        this.form.get('descuento')?.setValue(showDesc, { emitEvent: false });
      } catch {}
      // Si el parcial está activo, actualizar tope y valor sugerido del monto parcial con el PU efectivo
      if (this.tipo === 'mensualidad' && this.form.get('pago_parcial')?.value) {
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(Number(this.puDisplay || Number.MAX_SAFE_INTEGER))]);
        this.form.get('monto_parcial')?.setValue(this.puDisplay || 0, { emitEvent: false });
        this.form.get('monto_parcial')?.updateValueAndValidity({ emitEvent: false });
      }
    }
    if (changes['defaultMetodoPago']) {
      const v = (this.defaultMetodoPago || '').toString();
      if (v) {
        this.form.patchValue({ metodo_pago: v }, { emitEvent: false });
      }
    }
    // Revalidar tarjeta al cambiar inputs relevantes
    this.updateTarjetaValidators();
  }

  private configureByTipo(): void {
    if (this.tipo === 'mensualidad' || this.tipo === 'arrastre') {
      this.form.get('cantidad')?.setValidators([Validators.required, Validators.min(1), Validators.max(this.pendientes || 1)]);
      this.form.get('monto_manual')?.clearValidators();
      this.form.get('monto_manual')?.setValue(0, { emitEvent: false });
      // Si ya está activo pago parcial, aplicar estado/validadores correspondientes
      if (this.tipo === 'mensualidad' && this.form.get('pago_parcial')?.value) {
        this.form.get('cantidad')?.setValue(1, { emitEvent: false });
        this.form.get('cantidad')?.disable({ emitEvent: false });
        this.form.get('monto_parcial')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(Number(this.puDisplay || Number.MAX_SAFE_INTEGER))]);
        // Prefijar con el PU efectivo (ajustado)
        this.form.get('monto_parcial')?.setValue(this.puDisplay || 0, { emitEvent: false });
      } else {
        this.form.get('cantidad')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.clearValidators();
        this.form.get('monto_parcial')?.disable({ emitEvent: false });
      }
    } else if (this.tipo === 'reincorporacion') {
      // Reincorporación: sin cantidad; permitir pago parcial usando pu como tope
      this.form.get('cantidad')?.clearValidators();
      this.form.get('cantidad')?.setValue(1, { emitEvent: false });
      this.form.get('cantidad')?.disable({ emitEvent: false });
      this.form.get('monto_manual')?.clearValidators();
      this.form.get('monto_manual')?.setValue(0, { emitEvent: false });
      if (this.form.get('pago_parcial')?.value) {
        this.form.get('monto_parcial')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(this.puDisplay || Number.MAX_SAFE_INTEGER)]);
        this.form.get('monto_parcial')?.setValue(this.puDisplay || 0, { emitEvent: false });
      } else {
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.clearValidators();
        this.form.get('monto_parcial')?.disable({ emitEvent: false });
      }
    } else {
      this.form.get('cantidad')?.clearValidators();
      this.form.get('cantidad')?.setValue(1, { emitEvent: false });
      this.form.get('monto_manual')?.setValidators([Validators.required, Validators.min(0)]);
      // Asegurar que el parcial esté apagado en otros tipos
      this.form.get('pago_parcial')?.setValue(false, { emitEvent: false });
      this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
      this.form.get('monto_parcial')?.clearValidators();
      this.form.get('monto_parcial')?.disable({ emitEvent: false });
    }
    this.form.get('cantidad')?.updateValueAndValidity({ emitEvent: false });
    this.form.get('monto_manual')?.updateValueAndValidity({ emitEvent: false });
    this.form.get('monto_parcial')?.updateValueAndValidity({ emitEvent: false });
  }

  get title(): string {
    switch (this.tipo) {
      case 'rezagado': return 'Pago de Rezagado';
      case 'recuperacion': return 'Pago de Prueba de Recuperación';
      case 'arrastre': return 'Pago de Arrastre';
      case 'reincorporacion': return 'Pago de Reincorporación';
      default: return 'Pago de Mensualidades';
    }
  }

  recalcTotal(): void {
    let total = 0;
    if (this.tipo === 'mensualidad') {
      if (this.form.get('pago_parcial')?.value) {
        total = Number(this.form.get('monto_parcial')?.value || 0);
      } else {
        const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
        const sum = this.sumNextKCuotasRestantes(cant);
        if (sum > 0) {
          total = sum;
        } else {
          const puNext = Number(this.pu || 0);
          const avg = this.avgAsignMonto();
          const puTotales = Number(this.resumen?.totales?.pu_mensual || 0);
          const puSemestral = (avg !== null && avg !== undefined) ? avg : (puTotales || puNext);
          if (cant <= 0) total = 0; else if (cant === 1) total = puNext; else total = puNext + Math.max(0, cant - 1) * (puSemestral || puNext);
        }
      }
    } else if (this.tipo === 'arrastre') {
      const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
      total = cant * Number(this.pu || 0);
    } else if (this.tipo === 'reincorporacion') {
      // Total = monto parcial si aplica, caso contrario = PU (monto de reincorporación)
      if (this.form.get('pago_parcial')?.value) {
        total = Number(this.form.get('monto_parcial')?.value || 0);
      } else {
        total = Number(this.pu || 0);
      }
    } else {
      total = Number(this.form.get('monto_manual')?.value || 0);
    }
    this.form.get('costo_total')?.setValue(total, { emitEvent: false });
  }

  // Opciones para el selector de cantidad (1..pendientes)
  getCantidadOptions(): number[] {
    const p = Math.max(0, Number(this.pendientes || 0));
    return Array.from({ length: p }, (_, i) => i + 1);
  }

  getCantidadLabel(n: number): string {
    const start = this.getStartCuotaFromResumen();
    const cuota = start + Math.max(0, Number(n || 0)) - 1;
    const mes = this.getMesNombreByCuota(cuota);
    return mes ? `${n} - ${mes}` : `${n}`;
  }

  private getStartCuotaFromResumen(): number {
    try {
      // Priorizar override proveniente del padre cuando exista
      if (this.startCuotaOverride && this.startCuotaOverride > 0) return Number(this.startCuotaOverride);
      const list = this.getOrderedCuotasRestantes();
      if (list && list.length > 0) {
        const first = Number(list[0]?.numero || 0);
        if (first > 0) return first;
      }
      const next = Number(this.resumen?.mensualidad_next?.next_cuota?.numero_cuota || 0);
      return next > 0 ? next : 1;
    } catch { return 1; }
  }

  private getMesNombreByCuota(numeroCuota: number): string | null {
    try {
      const map = (this.resumen?.mensualidad_meses || []) as Array<any>;
      const hit = map.find(m => Number(m?.numero_cuota || 0) === Number(numeroCuota));
      if (hit && hit.mes_nombre) return String(hit.mes_nombre);
      const gestion = (this.resumen?.gestion || '').toString();
      const months = this.getGestionMonths(gestion);
      const idx = Number(numeroCuota) - 1;
      if (idx >= 0 && idx < months.length) return this.monthName(months[idx]);
      return null;
    } catch { return null; }
  }

  private getGestionMonths(gestion: string): number[] {
    try {
      const sem = parseInt((gestion || '').split('/')[0] || '0', 10);
      if (sem === 1) return [2,3,4,5,6];
      if (sem === 2) return [7,8,9,10,11];
      return [];
    } catch { return []; }
  }

  private monthName(n: number): string {
    const names = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return names[n] || String(n);
  }

  // Suma los montos restantes (netos) de las próximas k cuotas según resumen.asignacion_costos/asignaciones
  private sumNextKCuotasRestantes(k: number): number {
    if (!k) return 0;
    const list = this.getOrderedCuotasRestantes();
    let acc = 0; let c = 0;
    for (const it of list) { acc += it.restante; c++; if (c >= k) break; }
    return acc;
  }

  // Devuelve lista ordenada por numero_cuota con {numero, restante} usando monto neto (monto - descuento)
  private getOrderedCuotasRestantes(): Array<{ numero: number; restante: number; id_cuota_template: number|null; id_asignacion_costo: number|null; }> {
    const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
    const ord = (src || []).slice().sort((a: any, b: any) => Number(a?.numero_cuota || 0) - Number(b?.numero_cuota || 0));
    const out: Array<{ numero: number; restante: number; id_cuota_template: number|null; id_asignacion_costo: number|null; }> = [];
    for (const a of ord) {
      const bruto = this.toNumberLoose(a?.monto);
      const desc = this.toNumberLoose(a?.descuento);
      const montoNeto = (a?.monto_neto !== undefined && a?.monto_neto !== null) ? this.toNumberLoose(a?.monto_neto) : Math.max(0, bruto - desc);
      const pagado = this.toNumberLoose(a?.monto_pagado);
      const numero = Number(a?.numero_cuota || 0);
      let restante = Math.max(0, montoNeto - pagado);
      if (this.frontSaldos && Object.prototype.hasOwnProperty.call(this.frontSaldos, numero)) {
        const r = Number(this.frontSaldos[numero]);
        if (isFinite(r)) restante = Math.max(0, r);
      }
      if (restante > 0) out.push({
        numero,
        restante,
        id_cuota_template: (a?.id_cuota_template !== undefined && a?.id_cuota_template !== null) ? Number(a?.id_cuota_template) : null,
        id_asignacion_costo: (a?.id_asignacion_costo !== undefined && a?.id_asignacion_costo !== null) ? Number(a?.id_asignacion_costo) : null,
      });
    }
    // Si el padre indica una cuota inicial distinta (p.ej. porque ya se cobró el saldo en el front), filtrar
    if (this.startCuotaOverride && this.startCuotaOverride > 0) {
      const start = Number(this.startCuotaOverride);
      return out.filter(it => Number(it.numero) >= start);
    }
    return out;
  }

  // Obtiene el descuento configurado para una cuota específica desde el resumen
  private getDescuentoForCuota(numeroCuota: number): number {
    try {
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(numeroCuota));
      if (!hit) return 0;
      const d = (hit?.descuento !== undefined && hit?.descuento !== null) ? this.toNumberLoose(hit?.descuento) : 0;
      return d || 0;
    } catch { return 0; }
  }

  // Promedio nominal de las cuotas (monto sin considerar pagos) para fallback
  private avgAsignMonto(): number | null {
    try {
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      if (!src || !src.length) return null;
      const vals = src.map(a => this.toNumberLoose(a?.monto)).filter(n => n > 0);
      if (!vals.length) return null;
      const sum = vals.reduce((acc, n) => acc + n, 0);
      return sum / vals.length;
    } catch { return null; }
  }

  // Convierte valores como '800,00' o '1.200,50' a número 800.00 / 1200.50
  private toNumberLoose(v: any): number {
    if (typeof v === 'number') return isFinite(v) ? v : 0;
    if (v === null || v === undefined) return 0;
    const s = String(v).trim();
    if (!s) return 0;
    // quitar espacios y caracteres no numéricos salvo separadores
    let t = s.replace(/\s+/g, '');
    // si hay coma decimal, normalizar a punto; remover separadores de miles
    // estrategia: quitar todos los puntos y luego reemplazar la última coma por punto
    if (t.indexOf(',') >= 0 && t.indexOf('.') < 0) {
      t = t.replace(/\./g, '');
      t = t.replace(/,/g, '.');
    } else if (t.indexOf('.') >= 0 && t.indexOf(',') >= 0) {
      // formato tipo 1.234,56 -> quitar puntos (miles) y coma->punto
      t = t.replace(/\./g, '');
      t = t.replace(/,/g, '.');
    }
    const n = parseFloat(t);
    return isNaN(n) ? 0 : n;
  }

  // Indica si el método seleccionado corresponde a TARJETA según el catálogo recibido
  get isTarjeta(): boolean {
    const f = this.getSelectedForma();
    const id = (f?.id_forma_cobro ?? '').toString().trim().toUpperCase();
    const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
    if (id === 'V' || nameRaw.includes('VALES')) return false;
    const code = this.getSelectedCodigoSin();
    if (code !== null) {
      if ([2].includes(code)) return true; // 2 ~ Tarjeta (deb/cred)
    }
    const match = this.getSelectedForma();
    const nombre = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre.includes('TARJETA');
  }

  // Indica si el método seleccionado corresponde a CHEQUE
  get isCheque(): boolean {
    const f = this.getSelectedForma();
    const id = (f?.id_forma_cobro ?? '').toString().trim().toUpperCase();
    const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
    if (id === 'V' || nameRaw.includes('VALES')) return false;
    const code = this.getSelectedCodigoSin();
    if (code !== null) {
      if ([3].includes(code)) return true; // 3 ~ Cheque
    }
    const match = this.getSelectedForma();
    const nombre = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre.includes('CHEQUE');
  }

  // Indica si el método seleccionado corresponde a DEPOSITO/DEPÓSITO
  get isDeposito(): boolean {
    const f = this.getSelectedForma();
    const id = (f?.id_forma_cobro ?? '').toString().trim().toUpperCase();
    const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
    if (id === 'V' || nameRaw.includes('VALES')) return false;
    const code = this.getSelectedCodigoSin();
    if (code !== null) {
      if ([4].includes(code)) return true; // 4 ~ Depósito en cuenta
    }
    const match = this.getSelectedForma();
    const raw = (match?.descripcion_sin ?? match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    // tolerar acento
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre.includes('DEPOSITO');
  }

  // Helper: encuentra la forma seleccionada por codigo_sin o por id_forma_cobro (fallback)
  private getSelectedForma(): any | null {
    const val = (this.form.get('metodo_pago')?.value || '').toString();
    if (!val) return null;
    const list = (this.formasCobro || []) as any[];
    let match = list.find((f: any) => `${f?.codigo_sin}` === val);
    if (!match) match = list.find((f: any) => `${f?.id_forma_cobro}` === val);
    return match || null;
  }

  private getSelectedCodigoSin(): number | null {
    const match = this.getSelectedForma();
    if (!match) return null;
    const code = Number(match?.codigo_sin);
    return isFinite(code) ? code : null;
  }

  labelForma(f: any): string {
    const raw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim();
    if (raw) return raw;
    const code = Number(f?.codigo_sin);
    if (code === 1) return 'Efectivo';
    if (code === 2) return 'Tarjeta';
    if (code === 3) return 'Cheque';
    if (code === 4) return 'Deposito';
    if (code === 5) return 'Transferencia';
    return (f?.nombre || 'Otro');
  }

  // Activa/desactiva validadores requeridos para TARJETA o CHEQUE
  private updateTarjetaValidators(): void {
    const enableTarjeta = this.isTarjeta;
    const enableCheque = this.isCheque;
    const enableDeposito = this.isDeposito;
    const enableTransfer = this.isTransferencia;
    const enableQR = this.isQR;
    const idCuentaCtrl = this.form.get('id_cuentas_bancarias');
    const first4Ctrl = this.form.get('tarjeta_first4');
    const last4Ctrl = this.form.get('tarjeta_last4');
    const fechaDepCtrl = this.form.get('fecha_deposito');
    const nroDepCtrl = this.form.get('nro_deposito');
    const bancoOrigenCtrl = this.form.get('banco_origen');

    // id de cuenta bancaria requerido para cheque/deposito/transfer/tarjeta (no QR)
    if (enableTarjeta || enableCheque || enableDeposito || enableTransfer) {
      idCuentaCtrl?.setValidators([Validators.required]);
    } else {
      idCuentaCtrl?.clearValidators();
    }

    // Validadores específicos de tarjeta
    if (enableTarjeta) {
      first4Ctrl?.setValidators([Validators.required, Validators.pattern(/^\d{4}$/)]);
      last4Ctrl?.setValidators([Validators.required, Validators.pattern(/^\d{4}$/)]);
    } else {
      first4Ctrl?.clearValidators();
      last4Ctrl?.clearValidators();
    }

    // Validadores específicos de cheque/deposito/transfer y también TARJETA (EXCLUYE QR)
    if (enableCheque || enableDeposito || enableTransfer || enableTarjeta) {
      fechaDepCtrl?.setValidators([Validators.required]);
      nroDepCtrl?.setValidators([Validators.required]);
    } else {
      fechaDepCtrl?.clearValidators();
      nroDepCtrl?.clearValidators();
    }

    // Banco origen requerido para transferencia y tarjeta (EXCLUYE QR)
    if (enableTransfer || enableTarjeta) {
      bancoOrigenCtrl?.setValidators([Validators.required]);
    } else {
      bancoOrigenCtrl?.clearValidators();
    }

    // Comportamiento específico para QR: deshabilitar fecha/nro/banco y limpiar, autoseleccionar cuenta
    if (enableQR) {
      // Autoseleccionar cuenta si está vacía
      if (!idCuentaCtrl?.value) {
        const list = (this.cuentasBancarias || []) as any[];
        const first = list.find((c: any) => c?.habilitado_QR === true) || list[0];
        if (first) idCuentaCtrl?.setValue(first.id_cuentas_bancarias, { emitEvent: false });
      }
      // Deshabilitar y limpiar campos automáticos del QR
      fechaDepCtrl?.setValue('', { emitEvent: false });
      nroDepCtrl?.setValue('', { emitEvent: false });
      bancoOrigenCtrl?.setValue('', { emitEvent: false });
      fechaDepCtrl?.disable({ emitEvent: false });
      nroDepCtrl?.disable({ emitEvent: false });
      bancoOrigenCtrl?.disable({ emitEvent: false });
    } else {
      // Asegurar habilitados cuando no es QR
      fechaDepCtrl?.enable({ emitEvent: false });
      nroDepCtrl?.enable({ emitEvent: false });
      bancoOrigenCtrl?.enable({ emitEvent: false });
    }

    idCuentaCtrl?.updateValueAndValidity({ emitEvent: false });
    first4Ctrl?.updateValueAndValidity({ emitEvent: false });
    last4Ctrl?.updateValueAndValidity({ emitEvent: false });
    fechaDepCtrl?.updateValueAndValidity({ emitEvent: false });
    nroDepCtrl?.updateValueAndValidity({ emitEvent: false });
    bancoOrigenCtrl?.updateValueAndValidity({ emitEvent: false });
  }

  addAndClose(): void {
    // Validación explícita de TARJETA: 4 dígitos exactos en ambos campos
    if (this.isTarjeta) {
      const f4 = (this.form.get('tarjeta_first4')?.value || '').toString().trim();
      const l4 = (this.form.get('tarjeta_last4')?.value || '').toString().trim();
      if (!/^\d{4}$/.test(f4) || !/^\d{4}$/.test(l4)) {
        this.form.get('tarjeta_first4')?.markAsTouched();
        this.form.get('tarjeta_last4')?.markAsTouched();
        this.modalAlertMessage = 'Los números de la tarjeta deben tener exactamente 4 dígitos (primeros y últimos).';
        this.modalAlertType = 'warning';
        return;
      }
    }
    const esParcial = !!this.form.get('pago_parcial')?.value;
    if (esParcial) {
      const mp = this.form.get('monto_parcial');
      mp?.updateValueAndValidity({ emitEvent: false });
      if (mp?.hasError('max')) {
        mp.markAsTouched();
        this.modalAlertMessage = 'El monto que está ingresando excede el saldo.';
        this.modalAlertType = 'warning';
        return;
      }
    }
    if (!this.isFormValidForMetodo()) {
      const missing = this.collectMissingFieldsForMetodo();
      this.modalAlertMessage = missing.length ? `Complete los siguientes campos: ${missing.join(', ')}.` : 'Complete los campos obligatorios.';
      this.modalAlertType = 'warning';
      return;
    }
    // Validar comprobante explícitamente
    const compSelRaw = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
    if (compSelRaw !== 'RECIBO' && compSelRaw !== 'FACTURA') {
      this.form.get('comprobante')?.setErrors({ required: true });
      return;
    }

    const hoy = this.form.get('fecha_cobro')?.value || new Date().toISOString().slice(0, 10);
    const pagos: any[] = [];
    const compSel = compSelRaw;
    const tipo_documento = compSel === 'FACTURA' ? 'F' : (compSel === 'RECIBO' ? 'R' : '');
    const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';

    if (this.tipo === 'arrastre') {
      const next = this.resumen?.arrastre?.next_cuota || null;
      const monto = next ? Number(next?.monto || 0) : Number(this.pu || 0);
      pagos.push({
        id_forma_cobro: this.form.get('metodo_pago')?.value || null,
        nro_cobro: this.baseNro || 1,
        monto: monto,
        fecha_cobro: hoy,
        observaciones: this.composeObservaciones(),
        pu_mensualidad: monto,
        numero_cuota: next ? (Number(next?.numero_cuota || 0) || null) : null,
        id_cuota: next ? (next?.id_cuota_template ?? null) : null,
        id_asignacion_costo: next ? (next?.id_asignacion_costo ?? null) : null,
        // doc/medio
        tipo_documento,
        medio_doc,
        comprobante: compSel || 'NINGUNO',
        computarizada: this.form.get('computarizada')?.value,
        // bancarias
        id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
        banco_origen: this.form.get('banco_origen')?.value || null,
        fecha_deposito: this.form.get('fecha_deposito')?.value || null,
        nro_deposito: this.form.get('nro_deposito')?.value || null,
        tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
        tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
        // opcionales
        descuento: this.form.get('descuento')?.value || null,
        nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
        nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
      });
    } else if (this.tipo === 'mensualidad') {
      const esParcial = !!this.form.get('pago_parcial')?.value;
      if (esParcial) {
        const start = this.getStartCuotaFromResumen();
        const list = this.getOrderedCuotasRestantes();
        const first = list.find(it => Number(it.numero) === Number(start)) || list[0] || null;
        const numero_cuota = first ? (Number(first.numero || 0) || null) : null;
        const id_cuota_template = first ? (first.id_cuota_template ?? null) : null;
        const id_asignacion_costo = first ? (first.id_asignacion_costo ?? null) : null;
        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto: Number(this.form.get('monto_parcial')?.value || 0),
          fecha_cobro: hoy,
          observaciones: this.composeObservaciones(),
          pu_mensualidad: Number(this.puDisplay || this.pu || 0),
          pago_parcial: true,
          // doc/medio
          tipo_documento,
          medio_doc,
          comprobante: compSel || 'NINGUNO',
          computarizada: this.form.get('computarizada')?.value,
          // bancarias
          id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
          banco_origen: this.form.get('banco_origen')?.value || null,
          fecha_deposito: this.form.get('fecha_deposito')?.value || null,
          nro_deposito: this.form.get('nro_deposito')?.value || null,
          tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
          tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
          // opcionales
          descuento: this.form.get('descuento')?.value || null,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
          // targeting de cuota
          numero_cuota,
          id_cuota: id_cuota_template,
          id_asignacion_costo
        });
      } else {
        const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
        const list = this.getOrderedCuotasRestantes().slice(0, cant);
        let nro = this.baseNro || 1;
        if (list.length > 0) {
          for (let i = 0; i < list.length; i++) {
            const m = Number(list[i]?.restante || 0);
            const numero_cuota = Number(list[i]?.numero || 0) || null;
            const id_cuota_template = list[i]?.id_cuota_template ?? null;
            const id_asignacion_costo = list[i]?.id_asignacion_costo ?? null;
            pagos.push({
              id_forma_cobro: this.form.get('metodo_pago')?.value || null,
              nro_cobro: nro++,
              monto: m,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: m,
              numero_cuota,
              id_cuota: id_cuota_template,
              id_asignacion_costo,
              tipo_documento,
              medio_doc,
              comprobante: compSel || 'NINGUNO',
              computarizada: this.form.get('computarizada')?.value,
              id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
              banco_origen: this.form.get('banco_origen')?.value || null,
              fecha_deposito: this.form.get('fecha_deposito')?.value || null,
              nro_deposito: this.form.get('nro_deposito')?.value || null,
              tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
              tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
              descuento: this.form.get('descuento')?.value || null,
              nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
              nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
            });
          }
        } else {
          // Fallback enriquecido: primer pago = next restante (this.pu), siguientes = puSemestral
          const puNext = Number(this.pu || 0);
          const avg = this.avgAsignMonto();
          const puTotales = Number(this.resumen?.totales?.pu_mensual || 0);
          const puSemestral = (avg !== null && avg !== undefined) ? avg : (puTotales || puNext);
          const k = Math.max(0, cant);
          if (k >= 1) {
            pagos.push({
              id_forma_cobro: this.form.get('metodo_pago')?.value || null,
              nro_cobro: nro++,
              monto: puNext,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: puNext,
              numero_cuota: null,
              id_cuota: null,
              id_asignacion_costo: null,
              tipo_documento,
              medio_doc,
              comprobante: compSel || 'NINGUNO',
              computarizada: this.form.get('computarizada')?.value,
              id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
              banco_origen: this.form.get('banco_origen')?.value || null,
              fecha_deposito: this.form.get('fecha_deposito')?.value || null,
              nro_deposito: this.form.get('nro_deposito')?.value || null,
              tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
              tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
              descuento: this.form.get('descuento')?.value || null,
              nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
              nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
            });
          }
          for (let i = 1; i < k; i++) {
            pagos.push({
              id_forma_cobro: this.form.get('metodo_pago')?.value || null,
              nro_cobro: nro++,
              monto: puSemestral,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: puSemestral,
              numero_cuota: null,
              id_cuota: null,
              id_asignacion_costo: null,
              tipo_documento,
              medio_doc,
              comprobante: compSel || 'NINGUNO',
              computarizada: this.form.get('computarizada')?.value,
              id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
              banco_origen: this.form.get('banco_origen')?.value || null,
              fecha_deposito: this.form.get('fecha_deposito')?.value || null,
              nro_deposito: this.form.get('nro_deposito')?.value || null,
              tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
              tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
              descuento: this.form.get('descuento')?.value || null,
              nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
              nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
            });
          }
        }
      }
    } else if (this.tipo === 'reincorporacion') {
      // Reincorporación: un único registro, sin asignación de costos, con opción de pago parcial
      const esParcial = !!this.form.get('pago_parcial')?.value;
      const monto = esParcial ? Number(this.form.get('monto_parcial')?.value || 0) : Number(this.pu || 0);
      pagos.push({
        id_forma_cobro: this.form.get('metodo_pago')?.value || null,
        nro_cobro: this.baseNro || 1,
        monto,
        fecha_cobro: hoy,
        observaciones: this.composeObservaciones(),
        pu_mensualidad: this.pu || 0,
        cantidad: 1,
        detalle: 'Reincorporación',
        // doc/medio
        tipo_documento,
        medio_doc,
        comprobante: compSel || 'NINGUNO',
        computarizada: this.form.get('computarizada')?.value,
        // bancarias
        id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
        banco_origen: this.form.get('banco_origen')?.value || null,
        fecha_deposito: this.form.get('fecha_deposito')?.value || null,
        nro_deposito: this.form.get('nro_deposito')?.value || null,
        tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
        tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
        // opcionales
        descuento: this.form.get('descuento')?.value || null,
        nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
        nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
      });
    } else {
      // Rezagado o Recuperación: generamos un único registro
      const monto = Number(this.form.get('monto_manual')?.value || 0);
      pagos.push({
        id_forma_cobro: this.form.get('metodo_pago')?.value || null,
        nro_cobro: this.baseNro || 1,
        monto,
        fecha_cobro: hoy,
        observaciones: this.composeObservaciones(),
        pu_mensualidad: 0,
        // doc/medio
        tipo_documento,
        medio_doc,
        comprobante: compSel || 'NINGUNO',
        computarizada: this.form.get('computarizada')?.value,
        id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value || null,
        banco_origen: this.form.get('banco_origen')?.value || null,
        fecha_deposito: this.form.get('fecha_deposito')?.value || null,
        nro_deposito: this.form.get('nro_deposito')?.value || null,
        tarjeta_first4: this.form.get('tarjeta_first4')?.value || null,
        tarjeta_last4: this.form.get('tarjeta_last4')?.value || null,
        descuento: this.form.get('descuento')?.value || null,
        nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
        nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
      });
    }

    // Si es TARJETA / CHEQUE / DEPÓSITO / TRANSFERENCIA / QR, enviar además cabecera con la cuenta bancaria
    if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia || this.isQR) {
      const header = { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value };
      this.addPagos.emit({ pagos, cabecera: header });
    } else {
      this.addPagos.emit(pagos);
    }

    // Cerrar modal por Bootstrap si está presente
    const modalEl = document.getElementById('mensualidadModal');
    const bs = (window as any).bootstrap;
    if (modalEl && bs?.Modal) {
      const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
      instance.hide();
    }
    if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia) {
      this.resetTarjetaFields();
    }
    this.modalAlertMessage = '';
  }

  private getFieldLabel(name: string): string {
    const map: Record<string, string> = {
      metodo_pago: 'Método de Pago',
      comprobante: 'Comprobante',
      id_cuentas_bancarias: 'Cuenta destino',
      fecha_deposito: 'Fecha depósito',
      nro_deposito: 'Num. depósito',
      banco_origen: 'Banco Origen',
      tarjeta_first4: 'Nº Tarjeta (4 primeros)',
      tarjeta_last4: 'Nº Tarjeta (4 últimos)',
      cantidad: 'Cantidad',
      monto_parcial: this.tipo === 'reincorporacion' ? 'Saldo a pagar' : 'Monto parcial',
    };
    return map[name] || name;
  }

  private collectMissingFieldsForMetodo(): string[] {
    const out: string[] = [];
    const addIfMissing = (n: string) => {
      const c = this.form.get(n);
      if (!c) return;
      c.updateValueAndValidity({ emitEvent: false });
      const v = (c.value ?? '').toString().trim();
      const invalid = !v || c.invalid;
      if (invalid) {
        try { c.markAsTouched(); } catch {}
        out.push(this.getFieldLabel(n));
      }
    };
    const metodo = (this.form.get('metodo_pago')?.value || '').toString();
    if (!metodo) addIfMissing('metodo_pago');
    const comp = (this.form.get('comprobante')?.value || '').toString().toUpperCase();
    if (!(comp === 'RECIBO' || comp === 'FACTURA')) out.push(this.getFieldLabel('comprobante'));
    if (this.tipo === 'mensualidad') {
      const esParcial = !!this.form.get('pago_parcial')?.value;
      if (esParcial) {
        addIfMissing('monto_parcial');
      } else {
        addIfMissing('cantidad');
      }
    }
    if (this.isTarjeta) {
      ['id_cuentas_bancarias','fecha_deposito','nro_deposito','banco_origen','tarjeta_first4','tarjeta_last4'].forEach(addIfMissing);
    } else if (this.isTransferencia) {
      ['id_cuentas_bancarias','fecha_deposito','nro_deposito','banco_origen'].forEach(addIfMissing);
    } else if (this.isCheque || this.isDeposito) {
      ['id_cuentas_bancarias','fecha_deposito','nro_deposito'].forEach(addIfMissing);
    }
    return out;
  }

  private resetTarjetaFields(): void {
    const names = ['banco_origen','tarjeta_first4','tarjeta_last4','id_cuentas_bancarias','fecha_deposito','nro_deposito'];
    for (const n of names) {
      const c = this.form.get(n);
      if (!c) continue;
      const v = (n === 'id_cuentas_bancarias') ? '' : '';
      c.setValue(v, { emitEvent: false });
      c.markAsPristine();
      c.markAsUntouched();
      c.updateValueAndValidity({ emitEvent: false });
    }
  }

  private isFormValidForMetodo(): boolean {
    // Reglas mínimas comunes
    const metodo = (this.form.get('metodo_pago')?.value || '').toString();
    if (!metodo) return false;
    // El comprobante se valida aparte en addAndClose()

    // Bypass temprano: VALES/OTRO no exigen campos bancarios ni reglas adicionales
    const metodoVal = (this.form.get('metodo_pago')?.value || '').toString().trim().toUpperCase();
    if (metodoVal === 'V' || this.isOtro) {
      return true;
    }

    // Tipo mensualidad/reincorporación: validar pago parcial o cantidad
    if (this.tipo === 'mensualidad') {
      const esParcial = !!this.form.get('pago_parcial')?.value;
      if (esParcial) {
        const mp = this.form.get('monto_parcial');
        mp?.updateValueAndValidity({ emitEvent: false });
        if (!mp || mp.value === null || mp.value === undefined) return false;
        if (mp.hasError('required') || mp.hasError('min') || mp.hasError('max')) return false;
        // Asegurar > 0
        const mpNum = Number(mp.value);
        if (!isFinite(mpNum) || mpNum <= 0) return false;
      } else {
        const cant = this.form.get('cantidad');
        cant?.updateValueAndValidity({ emitEvent: false });
        if (!cant || !cant.value) return false;
        if (cant.hasError('required') || cant.hasError('min')) return false;
      }
    }

    // Validaciones por método
    if (this.isTarjeta) {
      const idCuenta = this.form.get('id_cuentas_bancarias');
      const f4 = this.form.get('tarjeta_first4');
      const l4 = this.form.get('tarjeta_last4');
      const fecha = this.form.get('fecha_deposito');
      const nro = this.form.get('nro_deposito');
      const banco = this.form.get('banco_origen');
      for (const c of [idCuenta, f4, l4, fecha, nro, banco]) {
        c?.updateValueAndValidity({ emitEvent: false });
        if (!c || !c.value) return false;
        if (c.hasError('required') || c.hasError('pattern')) return false;
      }
      return true;
    }
    if (this.isCheque || this.isDeposito || this.isTransferencia) {
      const idCuenta = this.form.get('id_cuentas_bancarias');
      const fecha = this.form.get('fecha_deposito');
      const nro = this.form.get('nro_deposito');
      idCuenta?.updateValueAndValidity({ emitEvent: false });
      fecha?.updateValueAndValidity({ emitEvent: false });
      nro?.updateValueAndValidity({ emitEvent: false });
      if (!idCuenta?.value || !fecha?.value || !nro?.value) return false;
    }
    // QR: no exige fecha/nro/banco (autogestionado)
    // OTRO / VALES: no exige campos bancarios
    return true;
  }

  private buildObservaciones(): string {
    const obs = (this.form.get('observaciones')?.value || '').toString().trim();
    const flags: string[] = [];
    if (this.tipo === 'rezagado' || this.form.get('rezagado')?.value) flags.push('Rezagado');
    if (this.tipo === 'recuperacion' || this.form.get('recuperacion')?.value) flags.push('Prueba de recuperación');
    if (this.tipo === 'reincorporacion') flags.push('Reincorporación');
    if (this.tipo === 'arrastre') flags.push('Arrastre');
    const joined = flags.length ? `[${flags.join(', ')}]` : '';
    return [joined, obs].filter(Boolean).join(' ').trim();
  }

  private composeObservaciones(): string {
    // Sólo el texto ingresado por el usuario (con flags), sin detalles de pago
    return this.buildObservaciones();
  }
}
