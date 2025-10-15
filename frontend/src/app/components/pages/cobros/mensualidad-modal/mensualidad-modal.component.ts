import { Component, EventEmitter, Input, OnChanges, OnInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';

@Component({
  selector: 'app-mensualidad-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  templateUrl: './mensualidad-modal.component.html',
  styleUrls: ['./mensualidad-modal.component.scss']
})
export class MensualidadModalComponent implements OnInit, OnChanges {
  @Input() resumen: any = null;
  @Input() formasCobro: any[] = [];
  @Input() cuentasBancarias: any[] = [];
  @Input() tipo: 'mensualidad' | 'rezagado' | 'recuperacion' | 'arrastre' = 'mensualidad';
  @Input() pendientes = 0;
  @Input() pu = 0; // precio unitario de mensualidad
  @Input() baseNro = 1; // nro_cobro inicial sugerido
  @Input() defaultMetodoPago: string = '';

  @Output() addPagos = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      metodo_pago: ['',[Validators.required]],
      cantidad: [1, [Validators.min(1)]],
      costo_total: [{ value: 0, disabled: true }],
      descuento: [''],
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
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    if (!nombre) return false;
    if (nombre.includes('TRANSFER') || nombre.includes('TARJETA') || nombre.includes('CHEQUE') || nombre.includes('DEPOSITO') || nombre.includes('QR')) return false;
    return nombre.includes('OTRO') || nombre.includes('VALES') || nombre.includes('PAGO POSTERIOR');
  }

  // Controla visibilidad del bloque bancario (Cheque/Depósito/Transferencia/QR)
  get showBancarioBlock(): boolean {
    if (this.isOtro) return false;
    // Para TARJETA ya existe un bloque específico arriba; evitar duplicar campos
    return this.isCheque || this.isDeposito || this.isTransferencia || this.isQR;
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
      if (this.tipo === 'mensualidad' || this.tipo === 'arrastre') {
        if (on) {
          // bloquear cantidad a 1 y habilitar monto_parcial
          this.form.get('cantidad')?.setValue(1, { emitEvent: false });
          this.form.get('cantidad')?.disable({ emitEvent: false });
          this.form.get('monto_parcial')?.enable({ emitEvent: false });
          this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(this.pu || Number.MAX_SAFE_INTEGER)]);
          // Prefijar el monto parcial con el restante sugerido (pu)
          this.form.get('monto_parcial')?.setValue(this.pu || 0, { emitEvent: false });
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
      }
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['pendientes'] || changes['pu'] || changes['tipo'] || changes['resumen']) {
      this.configureByTipo();
      this.recalcTotal();
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
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(0.01), Validators.max(this.pu || Number.MAX_SAFE_INTEGER)]);
        // Prefijar con el restante sugerido (pu)
        this.form.get('monto_parcial')?.setValue(this.pu || 0, { emitEvent: false });
      } else {
        this.form.get('cantidad')?.enable({ emitEvent: false });
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

  // Suma los montos restantes de las próximas k cuotas según resumen.asignacion_costos/asignaciones
  private sumNextKCuotasRestantes(k: number): number {
    if (!k) return 0;
    const list = this.getOrderedCuotasRestantes();
    let acc = 0; let c = 0;
    for (const it of list) { acc += it.restante; c++; if (c >= k) break; }
    return acc;
  }

  // Devuelve lista ordenada por numero_cuota con {numero, restante}
  private getOrderedCuotasRestantes(): Array<{ numero: number; restante: number; id_cuota_template: number|null; id_asignacion_costo: number|null; }> {
    const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
    const ord = (src || []).slice().sort((a: any, b: any) => Number(a?.numero_cuota || 0) - Number(b?.numero_cuota || 0));
    const out: Array<{ numero: number; restante: number; id_cuota_template: number|null; id_asignacion_costo: number|null; }> = [];
    for (const a of ord) {
      const monto = this.toNumberLoose(a?.monto);
      const pagado = this.toNumberLoose(a?.monto_pagado);
      const restante = Math.max(0, monto - pagado);
      if (restante > 0) out.push({
        numero: Number(a?.numero_cuota || 0),
        restante,
        id_cuota_template: (a?.id_cuota_template !== undefined && a?.id_cuota_template !== null) ? Number(a?.id_cuota_template) : null,
        id_asignacion_costo: (a?.id_asignacion_costo !== undefined && a?.id_asignacion_costo !== null) ? Number(a?.id_asignacion_costo) : null,
      });
    }
    return out;
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

    // id de cuenta bancaria requerido para tarjeta o cheque
    if (enableTarjeta || enableCheque || enableDeposito || enableTransfer || enableQR) {
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

    // Validadores específicos de cheque/deposito/transfer/QR y también TARJETA
    if (enableCheque || enableDeposito || enableTransfer || enableQR || enableTarjeta) {
      fechaDepCtrl?.setValidators([Validators.required]);
      nroDepCtrl?.setValidators([Validators.required]);
    } else {
      fechaDepCtrl?.clearValidators();
      nroDepCtrl?.clearValidators();
    }

    // Banco origen requerido para transferencia y tarjeta
    if (enableTransfer || enableTarjeta) {
      bancoOrigenCtrl?.setValidators([Validators.required]);
    } else {
      bancoOrigenCtrl?.clearValidators();
    }

    idCuentaCtrl?.updateValueAndValidity({ emitEvent: false });
    first4Ctrl?.updateValueAndValidity({ emitEvent: false });
    last4Ctrl?.updateValueAndValidity({ emitEvent: false });
    fechaDepCtrl?.updateValueAndValidity({ emitEvent: false });
    nroDepCtrl?.updateValueAndValidity({ emitEvent: false });
    bancoOrigenCtrl?.updateValueAndValidity({ emitEvent: false });
  }

  addAndClose(): void {
    if (!this.form.valid) return;
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
        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto: Number(this.form.get('monto_parcial')?.value || 0),
          fecha_cobro: hoy,
          observaciones: this.composeObservaciones(),
          pu_mensualidad: Number(this.pu || 0),
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
  }

  private buildObservaciones(): string {
    const obs = (this.form.get('observaciones')?.value || '').toString().trim();
    const flags: string[] = [];
    if (this.tipo === 'rezagado' || this.form.get('rezagado')?.value) flags.push('Rezagado');
    if (this.tipo === 'recuperacion' || this.form.get('recuperacion')?.value) flags.push('Prueba de recuperación');
    if (this.tipo === 'arrastre') flags.push('Arrastre');
    const joined = flags.length ? `[${flags.join(', ')}]` : '';
    return [joined, obs].filter(Boolean).join(' ').trim();
  }

  private composeObservaciones(): string {
    // Sólo el texto ingresado por el usuario (con flags), sin detalles de pago
    return this.buildObservaciones();
  }
}
