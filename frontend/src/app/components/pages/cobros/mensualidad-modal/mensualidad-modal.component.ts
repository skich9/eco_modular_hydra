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
  @Input() tipo: 'mensualidad' | 'rezagado' | 'recuperacion' = 'mensualidad';
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

  // Indica si el método seleccionado corresponde a pago con QR
  get isQR(): boolean {
    const id = (this.form.get('metodo_pago')?.value || '').toString();
    if (!id) return false;
    const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === `${id}`);
    const raw = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre.includes('QR');
  }

  // Indica si el método seleccionado corresponde a TRANSFERENCIA
  get isTransferencia(): boolean {
    const id = (this.form.get('metodo_pago')?.value || '').toString();
    if (!id) return false;
    const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === `${id}`);
    const raw = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre === 'TRANSFERENCIA';
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
      if (this.tipo === 'mensualidad') {
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
    if (changes['pendientes'] || changes['pu'] || changes['tipo']) {
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
    if (this.tipo === 'mensualidad') {
      this.form.get('cantidad')?.setValidators([Validators.required, Validators.min(1), Validators.max(this.pendientes || 1)]);
      this.form.get('monto_manual')?.clearValidators();
      this.form.get('monto_manual')?.setValue(0, { emitEvent: false });
      // Si ya está activo pago parcial, aplicar estado/validadores correspondientes
      if (this.form.get('pago_parcial')?.value) {
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
        // Fallback: si no hay data de cuotas en resumen, usar pu * cantidad
        const pu = Number(this.pu || 0);
        total = sum > 0 ? sum : (pu > 0 ? (pu * cant) : 0);
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
      const monto = Number(a?.monto || 0);
      const pagado = Number(a?.monto_pagado || 0);
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

  // Indica si el método seleccionado corresponde a TARJETA según el catálogo recibido
  get isTarjeta(): boolean {
    const id = (this.form.get('metodo_pago')?.value || '').toString();
    if (!id) return false;
    const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === `${id}`);
    const nombre = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre === 'TARJETA';
  }

  // Indica si el método seleccionado corresponde a CHEQUE
  get isCheque(): boolean {
    const id = (this.form.get('metodo_pago')?.value || '').toString();
    if (!id) return false;
    const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === `${id}`);
    const nombre = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    return nombre === 'CHEQUE';
  }

  // Indica si el método seleccionado corresponde a DEPOSITO/DEPÓSITO
  get isDeposito(): boolean {
    const id = (this.form.get('metodo_pago')?.value || '').toString();
    if (!id) return false;
    const match = (this.formasCobro || []).find((f: any) => `${f?.id_forma_cobro}` === `${id}`);
    const raw = (match?.nombre ?? match?.name ?? match?.descripcion ?? match?.label ?? '').toString().trim().toUpperCase();
    // tolerar acento
    const nombre = raw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
    return nombre === 'DEPOSITO';
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

    // Validadores específicos de cheque/deposito (exigimos fecha y número de depósito)
    if (enableCheque || enableDeposito || enableTransfer || enableQR) {
      fechaDepCtrl?.setValidators([Validators.required]);
      nroDepCtrl?.setValidators([Validators.required]);
    } else {
      fechaDepCtrl?.clearValidators();
      nroDepCtrl?.clearValidators();
    }

    // Banco origen requerido para transferencia (opcional para tarjeta)
    if (enableTransfer) {
      bancoOrigenCtrl?.setValidators([Validators.required]);
    } else {
      // Para tarjeta lo dejamos opcional
      if (enableTarjeta) bancoOrigenCtrl?.clearValidators(); else bancoOrigenCtrl?.clearValidators();
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

    if (this.tipo === 'mensualidad') {
      const esParcial = !!this.form.get('pago_parcial')?.value;
      if (esParcial) {
        pagos.push({
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
              descuento: this.form.get('descuento')?.value || null,
              nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
              nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
            });
          }
        } else {
          // Fallback: sin data de cuotas, generar 'cant' pagos usando PU
          const pu = Number(this.pu || 0);
          for (let i = 0; i < cant; i++) {
            pagos.push({
              nro_cobro: nro++,
              monto: pu,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: pu,
              numero_cuota: null,
              id_cuota: null,
              id_asignacion_costo: null,
              tipo_documento,
              medio_doc,
              comprobante: compSel || 'NINGUNO',
              computarizada: this.form.get('computarizada')?.value,
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
    const joined = flags.length ? `[${flags.join(', ')}]` : '';
    return [joined, obs].filter(Boolean).join(' ').trim();
  }

  private composeObservaciones(): string {
    const base = this.buildObservaciones();
    const idCuenta = this.form.get('id_cuentas_bancarias')?.value;
    const cuenta = (this.cuentasBancarias || []).find((c: any) => `${c?.id_cuentas_bancarias}` === `${idCuenta}`);
    const bancoDestino = cuenta ? `${cuenta.banco} ${cuenta.numero_cuenta}` : '';
    const fechaDep = (this.form.get('fecha_deposito')?.value || '').toString().trim();
    const nroDep = (this.form.get('nro_deposito')?.value || '').toString().trim();

    if (this.isTarjeta) {
      const bancoOrigen = (this.form.get('banco_origen')?.value || '').toString().trim();
      const first4 = (this.form.get('tarjeta_first4')?.value || '').toString().trim();
      const last4 = (this.form.get('tarjeta_last4')?.value || '').toString().trim();
      const detalles = [
        `TARJETA`,
        bancoOrigen && `Banco origen: ${bancoOrigen}`,
        (first4 && last4) && `Tarjeta **** ${first4}..${last4}`,
        fechaDep && `Fecha: ${fechaDep}`,
        nroDep && `Depósito: ${nroDep}`,
        bancoDestino && `Cuenta: ${bancoDestino}`
      ].filter(Boolean).join(' | ');
      return [base, detalles].filter(Boolean).join(' | ');
    }

    if (this.isCheque) {
      const detalles = [
        `CHEQUE`,
        fechaDep && `Fecha: ${fechaDep}`,
        nroDep && `Depósito: ${nroDep}`,
        bancoDestino && `Cuenta: ${bancoDestino}`
      ].filter(Boolean).join(' | ');
      return [base, detalles].filter(Boolean).join(' | ');
    }

    if (this.isDeposito) {
      const detalles = [
        `DEPOSITO`,
        fechaDep && `Fecha: ${fechaDep}`,
        nroDep && `Depósito: ${nroDep}`,
        bancoDestino && `Cuenta: ${bancoDestino}`
      ].filter(Boolean).join(' | ');
      return [base, detalles].filter(Boolean).join(' | ');
    }

    if (this.isTransferencia) {
      const bancoOrigen = (this.form.get('banco_origen')?.value || '').toString().trim();
      const detalles = [
        `TRANSFERENCIA`,
        bancoOrigen && `Banco origen: ${bancoOrigen}`,
        fechaDep && `Fecha: ${fechaDep}`,
        nroDep && `Depósito: ${nroDep}`,
        bancoDestino && `Cuenta: ${bancoDestino}`
      ].filter(Boolean).join(' | ');
      return [base, detalles].filter(Boolean).join(' | ');
    }

    if (this.isQR) {
      const detalles = [
        `QR`,
        fechaDep && `Fecha: ${fechaDep}`,
        nroDep && `Depósito: ${nroDep}`,
        bancoDestino && `Cuenta: ${bancoDestino}`
      ].filter(Boolean).join(' | ');
      return [base, detalles].filter(Boolean).join(' | ');
    }

    return base;
  }
}
