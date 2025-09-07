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
  @Input() tipo: 'mensualidad' | 'rezagado' | 'recuperacion' = 'mensualidad';
  @Input() pendientes = 0;
  @Input() pu = 0; // precio unitario de mensualidad
  @Input() baseNro = 1; // nro_cobro inicial sugerido
  @Input() defaultMetodoPago: string = '';

  @Output() addPagos = new EventEmitter<any[]>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      metodo_pago: ['',[Validators.required]],
      cantidad: [1, [Validators.min(1)]],
      costo_total: [{ value: 0, disabled: true }],
      descuento: [''],
      observaciones: [''],
      comprobante: ['NINGUNO'], // NINGUNO | FACTURA | RECIBO
      nro_factura: [''],
      nro_recibo: [''],
      computarizada: ['COMPUTARIZADA'], // COMPUTARIZADA | MANUAL
      pago_parcial: [false],
      // Para rezagado / recuperación
      rezagado: [false],
      recuperacion: [false],
      monto_manual: [0, [Validators.min(0)]], // usado cuando tipo != mensualidad
      fecha_cobro: [new Date().toISOString().slice(0, 10), Validators.required]
    });
  }

  ngOnInit(): void {
    this.recalcTotal();
    // Recalcular total al cambiar cantidad, descuento o monto_manual
    this.form.get('cantidad')?.valueChanges.subscribe(() => this.recalcTotal());
    this.form.get('monto_manual')?.valueChanges.subscribe(() => this.recalcTotal());
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
  }

  private configureByTipo(): void {
    if (this.tipo === 'mensualidad') {
      this.form.get('cantidad')?.setValidators([Validators.required, Validators.min(1)]);
      this.form.get('monto_manual')?.clearValidators();
      this.form.get('monto_manual')?.setValue(0, { emitEvent: false });
    } else {
      this.form.get('cantidad')?.clearValidators();
      this.form.get('cantidad')?.setValue(1, { emitEvent: false });
      this.form.get('monto_manual')?.setValidators([Validators.required, Validators.min(0)]);
    }
    this.form.get('cantidad')?.updateValueAndValidity({ emitEvent: false });
    this.form.get('monto_manual')?.updateValueAndValidity({ emitEvent: false });
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
      const cant = Number(this.form.get('cantidad')?.value || 0);
      total = Math.max(0, cant) * Number(this.pu || 0);
    } else {
      total = Number(this.form.get('monto_manual')?.value || 0);
    }
    this.form.get('costo_total')?.setValue(total, { emitEvent: false });
  }

  addAndClose(): void {
    if (!this.form.valid) return;

    const hoy = this.form.get('fecha_cobro')?.value || new Date().toISOString().slice(0, 10);
    const pagos: any[] = [];

    if (this.tipo === 'mensualidad') {
      const cant = Number(this.form.get('cantidad')?.value || 0);
      let nro = this.baseNro || 1;
      for (let i = 0; i < cant; i++) {
        pagos.push({
          nro_cobro: nro++,
          monto: Number(this.pu || 0),
          fecha_cobro: hoy,
          observaciones: this.form.get('observaciones')?.value || '',
          pu_mensualidad: Number(this.pu || 0),
          // campos opcionales útiles
          descuento: this.form.get('descuento')?.value || null,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
        });
      }
    } else {
      // Rezagado o Recuperación: generamos un único registro
      const monto = Number(this.form.get('monto_manual')?.value || 0);
      pagos.push({
        nro_cobro: this.baseNro || 1,
        monto,
        fecha_cobro: hoy,
        observaciones: this.buildObservaciones(),
        pu_mensualidad: 0,
        descuento: this.form.get('descuento')?.value || null,
        nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
        nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
      });
    }

    this.addPagos.emit(pagos);

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
}
