import { Component, EventEmitter, Input, OnChanges, OnInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { ClickLockDirective } from '../../../../directives/click-lock.directive';
import { ParametrosEconomicosService } from '../../../../services/parametros-economicos.service';
import { DefDescuentosService } from '../../../../services/def-descuentos.service';

@Component({
  selector: 'app-mensualidad-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, ClickLockDirective],
  templateUrl: './mensualidad-modal.component.html',
  styleUrls: ['./mensualidad-modal.component.scss']
})
export class MensualidadModalComponent implements OnInit, OnChanges {
  private _resumen: any = null;

  @Input()
  set resumen(value: any) {
    this._resumen = value;
  }
  get resumen(): any {
    return this._resumen;
  }

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

  // Configuración de descuento automático de semestre completo
  private descuentoSemestreActivar = false;
  private descuentoSemestreFechaLimite: string | null = null;
  private descuentoSemestreIdDefDescuento: number | null = null;
  private defDescuentosCache: any[] = [];

  constructor(
    private fb: FormBuilder,
    private parametrosService: ParametrosEconomicosService,
    private defDescuentosService: DefDescuentosService
  ) {
    console.log('[MensualidadModal] Constructor ejecutado');
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

  // Suma del MONTO BRUTO de las próximas k cuotas (para mostrar en Precio Unitario)
  private sumBrutoMenosPagadoNextK(k: number): number {
    try {
      const list = this.getOrderedCuotasRestantes();
      if (!list || !list.length || k <= 0) return 0;
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      let acc = 0; let c = 0;
      for (const it of list) {
        const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(it.numero));
        const bruto = this.toNumberLoose(hit?.monto);
        // Precio Unitario muestra el monto BRUTO de la cuota (sin restar pagado)
        acc += Math.max(0, bruto);
        c++; if (c >= k) break;
      }
      return acc;
    } catch { return 0; }
  }

  // Suma del DESCUENTO de las próximas k cuotas restantes
  private sumDescuentoNextK(k: number): number {
    try {
      const list = this.getOrderedCuotasRestantes();
      if (!list || !list.length || k <= 0) return 0;
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      let acc = 0; let c = 0;
      for (const it of list) {
        const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(it.numero));
        const d = this.toNumberLoose(hit?.descuento);
        acc += Math.max(0, d);
        c++; if (c >= k) break;
      }
      return acc;
    } catch { return 0; }
  }

  // Calcula la DEUDA REAL de las próximas k cuotas: monto - (monto_pagado + descuento)
  private sumDeudaRealNextK(k: number): number {
    try {
      const list = this.getOrderedCuotasRestantes();
      if (!list || !list.length || k <= 0) return 0;
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      let acc = 0; let c = 0;
      for (const it of list) {
        const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(it.numero));
        const monto = this.toNumberLoose(hit?.monto);
        const montoPagado = this.toNumberLoose(hit?.monto_pagado);
        const descuento = this.toNumberLoose(hit?.descuento);
        // Deuda real = monto - (monto_pagado + descuento)
        const deudaReal = Math.max(0, monto - (montoPagado + descuento));
        acc += deudaReal;
        c++; if (c >= k) break;
      }
      return acc;
    } catch { return 0; }
  }

  private updateDescuentoDisplay(): void {
    try {
      if (this.tipo === 'mensualidad') {
        const cantSel = Math.max(1, Number(this.form?.get('cantidad')?.value || 1));
        let d = this.sumDescuentoNextK(cantSel);
        const totalCuotasPendientes = this.pendientes || 0;

        if (d > 0) {
          this.form.get('descuento')?.setValue(d || 0, { emitEvent: false });
          return;
        }

        if (cantSel === totalCuotasPendientes && this.validarCuotasSinDescuentoPrevio(cantSel)) {
          const descuentoAuto = this.calcularDescuentoSemestreCompleto(cantSel);
          if (descuentoAuto > 0) {
            d = descuentoAuto;
          }
        }
        this.form.get('descuento')?.setValue(d || 0, { emitEvent: false });
      }
    } catch (error) {
      console.error('[MensualidadModal] Error en updateDescuentoDisplay:', error);
    }
  }

  // Obtiene el monto BRUTO y PAGADO de una cuota por número (para PU = bruto - pagado)
  private getCuotaBrutoPagadoByNumero(numeroCuota: number): { bruto: number; pagado: number } {
    try {
      const src: any[] = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      const hit = (src || []).find(a => Number(a?.numero_cuota || 0) === Number(numeroCuota));
      if (!hit) return { bruto: Number(this.pu || 0), pagado: 0 };
      const bruto = this.toNumberLoose(hit?.monto);
      const pagado = this.toNumberLoose(hit?.monto_pagado);
      return { bruto, pagado };
    } catch { return { bruto: Number(this.pu || 0), pagado: 0 }; }
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
      // Visual: en ARRASTRE mostrar el BRUTO de la cuota (monto de asignación de costos)
      if (this.tipo === 'arrastre') {
        const next: any = this.resumen?.arrastre?.next_cuota || null;
        const numero = Number(next?.numero_cuota || 0);
        const ida = Number(next?.id_asignacion_costo || 0);
        const idt = Number(next?.id_cuota_template || 0);
        let hit: any = null;
        // 1) arrastre.asignacion_costos.items
        const acItems: any[] = Array.isArray(this.resumen?.arrastre?.asignacion_costos?.items)
          ? this.resumen!.arrastre.asignacion_costos.items : [];
        if (acItems.length) {
          hit = acItems.find((a: any) => {
            const n = Number(a?.numero_cuota || a?.numero || 0);
            const _ida = Number(a?.id_asignacion_costo || 0);
            const _idt = Number(a?.id_cuota_template || 0);
            return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
          }) || null;
        }
        // 2) arrastre.asignaciones_arrastre
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
        // 3) raíz resumen.asignaciones_arrastre
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
          const bruto = this.toNumberLoose((hit as any)?.monto);
          return bruto > 0 ? bruto : Number(this.pu || 0);
        }
        // 4) fallback: objeto arrastre o next
        const arrObj: any = (this.resumen as any)?.arrastre || null;
        const brutoArr = this.toNumberLoose(arrObj?.monto);
        if (brutoArr > 0) return brutoArr;
        const brutoNext = this.toNumberLoose(next?.monto);
        return brutoNext > 0 ? brutoNext : Number(this.pu || 0);
      }
      if (this.tipo !== 'mensualidad') return Number(this.pu || 0);
      const cant = Math.max(1, Number(this.form?.get('cantidad')?.value || 1));
      return this.sumBrutoMenosPagadoNextK(cant);
    } catch { return Number(this.pu || 0); }
  }

  // Máximo permitido para pago parcial: monto - descuento - monto_pagado - lo que ya está en tabla detalle
  getParcialMax(): number {
    try {
      const start = this.getStartCuotaFromResumen();
      const asignaciones = this.resumen?.asignaciones || [];
      const cuota = asignaciones.find((a: any) => Number(a?.numero_cuota) === start);

      if (!cuota) {
        return Number.MAX_SAFE_INTEGER;
      }

      const monto = Number(cuota?.monto || 0);
      const descuento = Number(cuota?.descuento || 0);
      const montoPagado = Number(cuota?.monto_pagado || 0);

      // Calcular deuda real: monto - descuento - monto_pagado
      let deudaReal = Math.max(0, monto - descuento - montoPagado);

      // Si hay saldo frontal (lo que ya está en la tabla detalle), restarlo del máximo
      if (this.frontSaldos && Object.prototype.hasOwnProperty.call(this.frontSaldos, start)) {
        const saldoFrontal = Number(this.frontSaldos[start] || 0);
        if (isFinite(saldoFrontal) && saldoFrontal > 0) {
          deudaReal = saldoFrontal;
        }
      }

      return (isNaN(deudaReal) || deudaReal <= 0) ? Number.MAX_SAFE_INTEGER : deudaReal;
    } catch {
      return Number.MAX_SAFE_INTEGER;
    }
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

  // Filtrar cuentas bancarias según tipo de comprobante (Recibo/Factura)
  get cuentasBancariasFiltradas(): any[] {
    const comprobante = (this.form.get('comprobante')?.value || '').toString().toUpperCase();

    if (!comprobante || (comprobante !== 'RECIBO' && comprobante !== 'FACTURA')) {
      return this.cuentasBancarias || [];
    }

    // I_R = true → Recibo, I_R = false → Factura
    const esRecibo = comprobante === 'RECIBO';

    return (this.cuentasBancarias || []).filter((cuenta: any) => {
      const i_r = cuenta?.I_R;
      // Si I_R es true, la cuenta está habilitada para Recibo
      // Si I_R es false, la cuenta está habilitada para Factura
      return esRecibo ? i_r === true : i_r === false;
    });
  }

  ngOnInit(): void {
    console.log('[MensualidadModal] ngOnInit ejecutado');
    this.cargarParametrosDescuentoSemestre();
    this.cargarDefinicionesDescuentos();
    console.log('[MensualidadModal] Cache inicial:', this.defDescuentosCache.length);
    this.recalcTotal();

    // Recalcular total al cambiar cantidad, descuento o monto_manual
    this.form.get('cantidad')?.valueChanges.subscribe(() => {
      this.recalcTotal();
      this.updateDescuentoDisplay();
    });
    this.form.get('monto_manual')?.valueChanges.subscribe(() => this.recalcTotal());

    // Recalcular total al cambiar monto parcial (sin modificar el campo descuento)
    this.form.get('monto_parcial')?.valueChanges.subscribe((value) => {
      // Redondear automáticamente a entero si se ingresa un decimal
      if (value && !Number.isInteger(Number(value))) {
        const rounded = Math.round(Number(value));
        this.form.get('monto_parcial')?.setValue(rounded, { emitEvent: false });
      }
      this.recalcTotal();
    });
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
        const maxParcial = Math.floor(this.getParcialMax() || 0);
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(1), Validators.max(Number(maxParcial || Number.MAX_SAFE_INTEGER))]);
        // Dejar el campo vacío para que el usuario ingrese el monto que desea pagar
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        // NO modificar el descuento - debe mantener su valor original
      } else {
        this.form.get('cantidad')?.enable({ emitEvent: false });
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.clearValidators();
        this.form.get('monto_parcial')?.disable({ emitEvent: false });
        this.updateDescuentoDisplay();
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
      try {
        let d = 0;
        if (this.tipo === 'arrastre') {
          const next: any = this.resumen?.arrastre?.next_cuota || null;
          const numero = Number(next?.numero_cuota || 0);
          const ida = Number(next?.id_asignacion_costo || 0);
          const idt = Number(next?.id_cuota_template || 0);
          let hit: any = null;
          // 1) arrastre.asignacion_costos.items
          const acItems: any[] = Array.isArray(this.resumen?.arrastre?.asignacion_costos?.items)
            ? this.resumen!.arrastre.asignacion_costos.items : [];
          if (acItems.length) {
            hit = acItems.find((a: any) => {
              const n = Number(a?.numero_cuota || a?.numero || 0);
              const _ida = Number(a?.id_asignacion_costo || 0);
              const _idt = Number(a?.id_cuota_template || 0);
              return (ida && _ida === ida) || (idt && _idt === idt) || (numero && n === numero);
            }) || null;
          }
          // 2) arrastre.asignaciones_arrastre
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
          // 3) raíz resumen.asignaciones_arrastre
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
            const fromField = this.toNumberLoose((hit as any)?.descuento);
            d = fromField > 0 ? fromField : 0;
          } else if (next) {
            const fromNext = this.toNumberLoose((next as any)?.descuento ?? (next as any)?.monto_descuento ?? 0);
            d = fromNext > 0 ? fromNext : 0;
            // 4) último intento: el backend puede poner la cuota activa de arrastre a nivel arrastre
            if (!(d > 0)) {
              const arrObj: any = (this.resumen as any)?.arrastre || null;
              const arrDesc = this.toNumberLoose(arrObj?.descuento ?? 0);
              if (arrDesc > 0) d = arrDesc;
            }
          } else {
            d = 0;
          }
        } else {
          const cantSel = Math.max(1, Number(this.form?.get('cantidad')?.value || 1));
          d = this.sumDescuentoNextK(cantSel);
        }
        // Mostrar siempre el descuento aplicado a la cuota seleccionada
        this.form.get('descuento')?.setValue(d || 0, { emitEvent: false });
      } catch {}
      this.recalcTotal();
      // Si el parcial está activo, actualizar tope y valor sugerido del monto parcial con el PU efectivo
      if (this.tipo === 'mensualidad' && this.form.get('pago_parcial')?.value) {
        const maxParcial = Math.floor(this.getParcialMax() || 0);
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(1), Validators.max(Number(maxParcial || Number.MAX_SAFE_INTEGER))]);
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
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
        const maxParcial = Math.floor(this.getParcialMax() || 0);
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(1), Validators.max(Number(maxParcial || Number.MAX_SAFE_INTEGER))]);
        // Dejar el campo vacío para que el usuario ingrese el monto
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
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
        const maxParcial = Math.floor(this.puDisplay || 0);
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(1), Validators.max(maxParcial || Number.MAX_SAFE_INTEGER)]);
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
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
        // Usar deuda real: monto - (monto_pagado + descuento)
        const deudaReal = this.sumDeudaRealNextK(cant);
        if (deudaReal > 0) {
          total = deudaReal;
          const totalCuotasPendientes = this.pendientes || 0;
          // Descuento automático de semestre completo
          if (cant === totalCuotasPendientes && this.validarCuotasSinDescuentoPrevio(cant)) {
            const descuentoAuto = this.calcularDescuentoSemestreCompleto(cant);
            if (descuentoAuto > 0) {
              total = Math.max(0, total - descuentoAuto);
            }
          }
        } else {
          // Fallback si no hay datos de asignaciones
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

  get facturaDeshabilitada(): boolean {
    if (!this.resumen) return false;

    // Solo bloquear Factura si hay descuentos INSTITUCIONALES (d_i: true)
    const descuentosAplicados = this.resumen?.descuentos_aplicados || [];
    const tieneDescuentoConDI = Array.isArray(descuentosAplicados) && descuentosAplicados.some((desc: any) => {
      const definicion = desc?.definicion || desc?.def_descuento_beca || {};
      const di = definicion?.d_i;
      return di === 1 || di === true || di === '1';
    });

    // Verificar si el descuento de semestre completo es institucional
    const cantSel = Math.max(1, Number(this.form.get('cantidad')?.value || 1));
    const totalCuotasPendientes = this.pendientes || 0;
    let descuentoSemestreEsInstitucional = false;

    if (cantSel === totalCuotasPendientes && this.descuentoSemestreIdDefDescuento) {
      if (this.descuentoSemestreActivar) {
        let dentroFecha = true;
        if (this.descuentoSemestreFechaLimite) {
          const hoy = new Date();
          const limite = new Date(this.descuentoSemestreFechaLimite);
          hoy.setHours(0, 0, 0, 0);
          limite.setHours(0, 0, 0, 0);
          dentroFecha = hoy <= limite;
        }

        if (dentroFecha) {
          const defDescuento = this.obtenerDefinicionDescuentoDirecto(this.descuentoSemestreIdDefDescuento);
          if (defDescuento) {
            const di = defDescuento?.d_i;
            descuentoSemestreEsInstitucional = di === 1 || di === true || di === '1';
          }
        }
      }
    }

    // SOLO bloquear si hay descuentos institucionales (d_i: true)
    // Las becas académicas y descuentos regulares SÍ permiten Factura
    const disabled = !!tieneDescuentoConDI || descuentoSemestreEsInstitucional;

    if (disabled) {
      const comprobanteActual = this.form.get('comprobante')?.value;
      if (comprobanteActual === 'FACTURA') {
        setTimeout(() => {
          this.form.patchValue({ comprobante: 'RECIBO' }, { emitEvent: false });
        }, 0);
      }
    }

    return disabled;
  }

  // Calcular descuento prorrateado según la fórmula especificada (4 decimales internos)
  calcularDescuentoProrrateado(montoPagoParcial: number): number {
    console.log('[MensualidadModal] calcularDescuentoProrrateado llamado:', {
      montoPagoParcial,
      tieneResumen: !!this.resumen
    });

    if (!this.resumen || !montoPagoParcial || montoPagoParcial <= 0) {
      console.log('[MensualidadModal] Retornando 0 - condiciones iniciales no cumplidas');
      return 0;
    }

    const cantidad = Number(this.form.get('cantidad')?.value || 1);
    const asignaciones = this.resumen?.asignaciones || [];
    const startCuota = this.getStartCuotaFromResumen();

    console.log('[MensualidadModal] Datos para cálculo:', {
      cantidad,
      startCuota,
      totalAsignaciones: asignaciones.length,
      primerasCuotas: asignaciones.slice(0, 3).map((a: any) => ({
        numero_cuota: a?.numero_cuota,
        descuento: a?.descuento,
        total_debe_pagar: a?.total_debe_pagar,
        descuento_aplicado: a?.descuento_aplicado,
        monto_pagado: a?.monto_pagado
      }))
    });

    if (!asignaciones || asignaciones.length === 0) {
      throw new Error('No hay asignaciones disponibles para calcular descuento prorrateado');
    }

    let descuentoTotal = 0;

    // Calcular descuento prorrateado para cada cuota que se va a pagar
    for (let i = 0; i < cantidad; i++) {
      const numeroCuota = startCuota + i;
      const cuota = asignaciones.find(
        (a: any) => Number(a?.numero_cuota) === numeroCuota
      );

      if (!cuota) continue;

      const descuentoPago = Number(cuota?.descuento || 0);
      console.log('[MensualidadModal] Cuota encontrada:', {
        numeroCuota,
        descuentoPago,
        monto_pagado: cuota?.monto_pagado,
        total_debe_pagar: cuota?.total_debe_pagar,
        descuento_aplicado: cuota?.descuento_aplicado
      });

      if (descuentoPago <= 0) {
        console.log('[MensualidadModal] Cuota sin descuento, continuando...');
        continue;
      }

      const deudaPagada = Number(cuota?.monto_pagado || 0);
      const totalDebePagar = Number(cuota?.total_debe_pagar || 0);
      const descuentoAplicado = Number(cuota?.descuento_aplicado || 0);

      if (totalDebePagar <= 0) {
        throw new Error(
          `Total a pagar inválido para cuota ${numeroCuota}: ${totalDebePagar}`
        );
      }

      // Fórmula: descuento_pago * (monto_pago_parcial / total_debe_pagar)
      // Solo calculamos el descuento proporcional al pago actual, sin restar descuento_aplicado
      const proporcion = montoPagoParcial / totalDebePagar;
      const descuentoProrrateado = descuentoPago * proporcion;

      console.log('[MensualidadModal] Cálculo de descuento prorrateado:', {
        montoPagoParcial,
        totalDebePagar,
        descuentoPago,
        proporcion,
        descuentoProrrateado,
        descuentoFinal: Math.max(0, descuentoProrrateado)
      });

      descuentoTotal += Math.max(0, descuentoProrrateado);
    }

    console.log('[MensualidadModal] Descuento total calculado:', descuentoTotal);

    // Redondear a 4 decimales
    return Math.round(descuentoTotal * 10000) / 10000;
  }

  // ELIMINADO: Ya no se actualiza automáticamente el campo descuento
  // El campo descuento se mantiene sin cambios según indicaciones del dev senior

  // Getter para obtener el descuento prorrateado basado en el monto actual
  get descuentoProrrateado(): number {
    const montoParcial = Number(this.form.get('monto_parcial')?.value || 0);
    const pagoParcial = this.form.get('pago_parcial')?.value;

    console.log('[MensualidadModal] Getter descuentoProrrateado llamado:', {
      montoParcial,
      pagoParcial
    });

    if (!pagoParcial || montoParcial <= 0) {
      console.log('[MensualidadModal] Getter retorna 0 - condiciones no cumplidas');
      return 0;
    }

    try {
      const descuento = this.calcularDescuentoProrrateado(montoParcial);
      const resultado = Math.round(descuento * 100) / 100;
      console.log('[MensualidadModal] Getter descuentoProrrateado resultado:', resultado);
      return resultado;
    } catch (error) {
      console.error('[MensualidadModal] Error en getter descuentoProrrateado:', error);
      return 0;
    }
  }

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
    console.log('[MensualidadModal] ===== INICIO addAndClose() =====');
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
    const descuentos: any[] = [];
    const compSel = compSelRaw;
    const tipo_documento = compSel === 'FACTURA' ? 'F' : (compSel === 'RECIBO' ? 'R' : '');
    const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';

    if (this.tipo === 'arrastre') {
      const next = this.resumen?.arrastre?.next_cuota || null;
      // Para arrastre, el PU/monto debe reflejar el NETO (monto - descuento) del item de arrastre
      let monto = next ? this.toNumberLoose((next as any)?.monto_neto) : 0;
      if (!(monto > 0) && next) {
        const bruto = this.toNumberLoose((next as any)?.monto);
        const desc = this.toNumberLoose((next as any)?.descuento);
        monto = Math.max(0, bruto - desc);
      }
      if (!(monto > 0)) monto = Number(this.pu || 0);
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
        const monto = Number(this.form.get('monto_parcial')?.value || 0);
        // Calcular el descuento prorrateado para este pago parcial
        const descuentoProrrateado = this.calcularDescuentoProrrateado(monto);
        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto,
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
          // Enviar el descuento prorrateado calculado
          descuento: descuentoProrrateado || null,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
          // targeting de cuota
          numero_cuota,
          id_cuota: id_cuota_template,
          id_asignacion_costo
        });
      } else {
        console.log('[MensualidadModal] INICIO bloque pago completo mensualidad');
        const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
        const list = this.getOrderedCuotasRestantes().slice(0, cant);
        let nro = this.baseNro || 1;
        console.log('[MensualidadModal] Datos iniciales:', { cant, listLength: list.length, nro });
        if (list.length > 0) {
          // Calcular descuento automático de semestre completo
          const descuentoAutoTotal = this.calcularDescuentoSemestreCompleto(cant);
          const montoTotalSinDescuentoAuto = this.sumNextKCuotasRestantes(cant);
          console.log('[MensualidadModal] Calculando descuentos:', {
            cant,
            descuentoAutoTotal,
            montoTotalSinDescuentoAuto,
            descuentoSemestreIdDefDescuento: this.descuentoSemestreIdDefDescuento,
            listLength: list.length
          });

          for (let i = 0; i < list.length; i++) {
            const m = Number(list[i]?.restante || 0); // neto (PU - descuento)
            const numero_cuota = Number(list[i]?.numero || 0) || null;
            const id_cuota_template = list[i]?.id_cuota_template ?? null;
            const id_asignacion_costo = list[i]?.id_asignacion_costo ?? null;

            // Obtener datos de la cuota para detectar pagos previos
            const cuotaData = numero_cuota ? (this.resumen?.asignaciones || []).find((a: any) => Number(a?.numero_cuota) === numero_cuota) : null;
            const montoPagadoPrevio = Number(cuotaData?.monto_pagado || 0);
            const montoBrutoOriginal = Number(cuotaData?.monto || 0);
            const descuentoOriginal = Number(cuotaData?.descuento || 0);
            const montoNetoTotal = montoBrutoOriginal - descuentoOriginal;
            const saldoRestante = Math.max(0, montoNetoTotal - montoPagadoPrevio);

            // Detectar si es un pago parcial automático (hay pagos previos y se paga el saldo restante)
            const esPagoParcialAuto = montoPagadoPrevio > 0 && m <= saldoRestante && saldoRestante > 0;

            // Calcular P/U y descuento prorrateados si hay pagos previos
            let pu_unit = 0;
            let descUnit = 0;

            if (esPagoParcialAuto && saldoRestante > 0) {
              // Pago parcial detectado automáticamente: calcular valores prorrateados
              const proporcion = m / saldoRestante;
              const puRestante = montoBrutoOriginal - (montoBrutoOriginal * (montoPagadoPrevio / montoNetoTotal));
              const descRestante = descuentoOriginal - (descuentoOriginal * (montoPagadoPrevio / montoNetoTotal));

              // Calcular con 4 decimales para precisión
              let pu_unit_4 = Math.round(puRestante * proporcion * 10000) / 10000;
              let descUnit_4 = Math.round(descRestante * proporcion * 10000) / 10000;

              // Ajustar para que sume exactamente el monto
              const diferencia = m - (pu_unit_4 - descUnit_4);
              if (Math.abs(diferencia) > 0.0001) {
                pu_unit_4 += diferencia;
              }

              // Redondear a 2 decimales para mostrar
              pu_unit = Math.round(pu_unit_4 * 100) / 100;
              descUnit = Math.round(descUnit_4 * 100) / 100;

              // Ajuste final
              const diferencia2 = m - (pu_unit - descUnit);
              if (Math.abs(diferencia2) > 0.01) {
                pu_unit += diferencia2;
              }
            } else {
              // Pago completo o sin pagos previos: usar valores normales
              if (numero_cuota) {
                const bp = this.getCuotaBrutoPagadoByNumero(numero_cuota);
                pu_unit = Math.max(0, Number(bp.bruto || 0) - Number(bp.pagado || 0));
              }
              descUnit = this.getDescuentoForCuota(Number(numero_cuota || 0)) || 0;
            }

            // Distribuir descuento automático proporcionalmente
            if (descuentoAutoTotal > 0 && montoTotalSinDescuentoAuto > 0) {
              const proporcion = m / montoTotalSinDescuentoAuto;
              const descuentoAutoCuota = descuentoAutoTotal * proporcion;
              descUnit += descuentoAutoCuota;

              // Crear registro de descuento para esta cuota
              console.log('[MensualidadModal] Evaluando creación de descuento:', {
                descuentoAutoCuota,
                id_asignacion_costo,
                descuentoSemestreIdDefDescuento: this.descuentoSemestreIdDefDescuento,
                cumpleCondicion: descuentoAutoCuota > 0 && id_asignacion_costo && this.descuentoSemestreIdDefDescuento
              });

              if (descuentoAutoCuota > 0 && id_asignacion_costo && this.descuentoSemestreIdDefDescuento) {
                const descuentoItem = {
                  id_asignacion_costo: id_asignacion_costo,
                  cod_beca: this.descuentoSemestreIdDefDescuento,
                  monto_descuento: Math.round(descuentoAutoCuota * 100) / 100,
                  observaciones: 'Descuento por pago de semestre completo'
                };
                descuentos.push(descuentoItem);
                console.log('[MensualidadModal] Descuento agregado:', descuentoItem);
              }
            }

            pagos.push({
              id_forma_cobro: this.form.get('metodo_pago')?.value || null,
              nro_cobro: nro++,
              monto: m,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: pu_unit,
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
              descuento: descUnit || null,
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
              descuento: 0,
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
              descuento: 0,
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

    console.log('[MensualidadModal] Estado final antes de enviar:', {
      pagosCount: pagos.length,
      descuentosCount: descuentos.length,
      descuentos: descuentos
    });

    // Si es TARJETA / CHEQUE / DEPÓSITO / TRANSFERENCIA / QR, enviar además cabecera con la cuenta bancaria
    if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferencia || this.isQR) {
      const header = { id_cuentas_bancarias: this.form.get('id_cuentas_bancarias')?.value };
      const payload: any = { pagos, cabecera: header };
      if (descuentos.length > 0) {
        payload.descuentos = descuentos;
        console.log('[MensualidadModal] Enviando descuentos con cabecera:', { count: descuentos.length, descuentos });
      }
      this.addPagos.emit(payload);
    } else {
      const payload: any = descuentos.length > 0 ? { pagos, descuentos } : pagos;
      if (descuentos.length > 0) {
        console.log('[MensualidadModal] Enviando descuentos sin cabecera:', { count: descuentos.length, descuentos });
      }
      this.addPagos.emit(payload);
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

  private cargarParametrosDescuentoSemestre(): void {
    this.parametrosService.getAll().subscribe({
      next: (res) => {
        if (res.success && res.data) {
          const params = res.data;
          const activar = params.find((p: any) => {
            const id = Number(p?.id_parametro_economico || 0);
            const nombre = (p?.nombre || '').toString().toLowerCase().trim();
            return id === 1 || nombre === 'descuento_semestre_completo_activar';
          });
          const fecha = params.find((p: any) => {
            const id = Number(p?.id_parametro_economico || 0);
            const nombre = (p?.nombre || '').toString().toLowerCase().trim();
            return id === 2 || nombre === 'descuento_semestre_completo_fecha';
          });
          const idDescuento = params.find((p: any) => {
            const id = Number(p?.id_parametro_economico || 0);
            const nombre = (p?.nombre || '').toString().toLowerCase().trim();
            return id === 3 || nombre === 'descuento_semestre_completo_porcentaje';
          });

          const valorActivar = String(activar?.valor || '').toLowerCase().trim();
          this.descuentoSemestreActivar = valorActivar === 'true' || valorActivar === '1';
          this.descuentoSemestreFechaLimite = fecha?.valor || null;
          this.descuentoSemestreIdDefDescuento = Number(idDescuento?.valor || 0) || null;
          console.log('[MensualidadModal] Parámetros descuento semestre cargados:', {
            activar: this.descuentoSemestreActivar,
            fechaLimite: this.descuentoSemestreFechaLimite,
            idDefDescuento: this.descuentoSemestreIdDefDescuento
          });
        }
      },
      error: () => {
        this.descuentoSemestreActivar = false;
        this.descuentoSemestreFechaLimite = null;
        this.descuentoSemestreIdDefDescuento = null;
      }
    });
  }

  private calcularDescuentoSemestreCompleto(cantidadSeleccionada: number): number {
    try {
      if (!this.descuentoSemestreActivar) return 0;

      if (this.descuentoSemestreFechaLimite) {
        const hoy = new Date();
        const limite = new Date(this.descuentoSemestreFechaLimite);
        hoy.setHours(0, 0, 0, 0);
        limite.setHours(0, 0, 0, 0);
        if (hoy > limite) return 0;
      }

      if (!this.descuentoSemestreIdDefDescuento || this.descuentoSemestreIdDefDescuento <= 0) return 0;

      const totalCuotasPendientes = this.pendientes || 0;
      if (cantidadSeleccionada !== totalCuotasPendientes) return 0;

      const montoTotal = this.sumNextKCuotasRestantes(cantidadSeleccionada);
      if (montoTotal <= 0) return 0;

      const defDescuento = this.obtenerDefinicionDescuentoDirecto(this.descuentoSemestreIdDefDescuento);
      if (!defDescuento) return 0;

      const esPorcentaje = defDescuento.porcentaje === true || defDescuento.porcentaje === 1;
      const valor = Number(defDescuento.monto || 0);

      if (!esPorcentaje) return Math.max(0, valor);
      if (valor <= 0 || valor > 100) return 0;

      const descuento = (montoTotal * valor) / 100;
      return Math.max(0, descuento);
    } catch (error) {
      return 0;
    }
  }

  private validarCuotasSinDescuentoPrevio(cantidadSeleccionada: number): boolean {
    try {
      const asignaciones = this.resumen?.asignaciones || [];
      const start = this.getStartCuotaFromResumen();
      for (let i = 0; i < cantidadSeleccionada; i++) {
        const nro = start + i;
        const cuota = asignaciones.find((a: any) => Number(a?.numero_cuota || 0) === Number(nro));
        const descNum = Number(cuota?.descuento || 0);
        if (descNum > 0) return false;
      }
      return true;
    } catch {
      return false;
    }
  }

  private cargarDefinicionesDescuentos(): void {
    console.log('[MensualidadModal] Iniciando carga de definiciones de descuentos...');
    this.defDescuentosService.getAll().subscribe({
      next: (res) => {
        console.log('[MensualidadModal] Respuesta del servicio:', res);
        if (res.success && res.data) {
          console.log('[MensualidadModal] Total de registros recibidos:', res.data.length);
          console.log('[MensualidadModal] Primeros 3 registros:', res.data.slice(0, 3));

          this.defDescuentosCache = res.data;
          console.log('[MensualidadModal] Definiciones de descuentos cargadas:', {
            descuentos: this.defDescuentosCache.length,
            primeros3Descuentos: this.defDescuentosCache.slice(0, 3)
          });
        } else {
          console.warn('[MensualidadModal] Respuesta sin éxito o sin datos:', res);
        }
      },
      error: (err) => {
        console.error('[MensualidadModal] Error al cargar definiciones de descuentos:', err);
        this.defDescuentosCache = [];
      }
    });
  }

  private obtenerDefinicionDescuento(idDefDescuento: number): any {
    try {
      const descuentosAplicados = this.resumen?.descuentos_aplicados || [];
      console.log('[MensualidadModal] Buscando en descuentos_aplicados:', { count: descuentosAplicados.length, idBuscado: idDefDescuento });
      for (const desc of descuentosAplicados) {
        const def = desc?.definicion || desc?.def_descuento_beca || {};
        const id = Number(def?.id_def_descuento_beca || 0);
        if (id === idDefDescuento) return def;
      }
      return null;
    } catch {
      return null;
    }
  }

  private obtenerDefinicionDescuentoDirecto(idDefDescuento: number): any {
    try {
      const def = this.defDescuentosCache.find((d: any) => Number(d?.cod_descuento || 0) === idDefDescuento);
      if (def) return def;

      const descuentosAplicados = this.resumen?.descuentos_aplicados || [];
      for (const desc of descuentosAplicados) {
        const defItem = desc?.definicion || desc?.def_descuento_beca || {};
        const id = Number(defItem?.cod_descuento || defItem?.cod_beca || 0);
        if (id === idDefDescuento) return defItem;
      }
      return null;
    } catch (error) {
      console.error('[MensualidadModal] Error al obtener definición:', error);
      return null;
    }
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
