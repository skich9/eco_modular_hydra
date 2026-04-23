import { Component, EventEmitter, Input, OnChanges, OnInit, AfterViewInit, Output, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { ClickLockDirective } from '../../../../directives/click-lock.directive';
import { ParametrosEconomicosService } from '../../../../services/parametros-economicos.service';
import { DefDescuentosService } from '../../../../services/def-descuentos.service';
import { isOnOrBeforeDeadlineLocal } from '../../../../utils/date-only.util';

@Component({
  selector: 'app-mensualidad-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, ClickLockDirective],
  templateUrl: './mensualidad-modal.component.html',
  styleUrls: ['./mensualidad-modal.component.scss']
})
export class MensualidadModalComponent implements OnInit, OnChanges, AfterViewInit {
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
  @Input() morasPendientes: any[] = [];
  @Input() gestionesActivas: any[] = [];
  @Input() carreras: any[] = [];
  @Input() tipo: 'mensualidad' | 'rezagado' | 'recuperacion' | 'arrastre' | 'reincorporacion' | 'mora' = 'mensualidad';
  // Nota: también soporta 'reincorporacion' como tipo adicional
  @Input() pendientes = 0;
  @Input() pu = 0; // precio unitario de mensualidad
  @Input() baseNro = 1; // nro_cobro inicial sugerido
  @Input() defaultMetodoPago: string = '';
  @Input() startCuotaOverride: number | null = null;
  @Input() frontSaldos: Record<number, number> = {};
  @Input() detalleFactura: any[] = [];

  @Output() addPagos = new EventEmitter<any>();

  form: FormGroup;
  modalAlertMessage = '';
  modalAlertType: 'success' | 'error' | 'warning' = 'warning';
  showOrdenPagoInfo = false;
  ordenPagoInfoMessage = '';
  moraPendienteDetectada: any = null;
  private lastMorasLen: number = -1;
  botonDeshabilitado = false; // Se actualiza cuando cambia detalleFactura
  private modalOpenListenerBound = false;

  private isMoraEstadoPendiente(estadoRaw: any): boolean {
    try {
      const estado = (estadoRaw || '').toString().toUpperCase();
      return estado === 'PENDIENTE' || estado === 'CERRADA_SIN_CUOTA' || estado === 'CONGELADA_PRORROGA';
    } catch {
      return false;
    }
  }

  private parseDateLoose(raw: any): Date | null {
    try {
      if (!raw) return null;
      if (raw instanceof Date) return raw;
      const s = (raw || '').toString().trim();
      if (!s) return null;
      // Evitar desfase por timezone: strings tipo 'YYYY-MM-DD' se interpretan como UTC en JS.
      // Aquí las convertimos a fecha LOCAL (00:00 local) para que el date pipe muestre el día correcto.
      if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
        const parts = s.split('-').map((x: string) => Number(x));
        const y = parts[0];
        const m = parts[1];
        const d0 = parts[2];
        if (y && m && d0) {
          const local = new Date(y, m - 1, d0, 0, 0, 0, 0);
          if (!isNaN(local.getTime())) return local;
        }
      }
      const d = new Date(s);
      if (!isNaN(d.getTime())) return d;
      return null;
    } catch {
      return null;
    }
  }

  get morasTabla(): any[] {
    try {
      const list: any[] = Array.isArray(this.morasPendientes) ? this.morasPendientes : [];

      if (this.tipo === 'arrastre') {
        const next: any = this.resumen?.arrastre?.next_cuota || null;
        if (!next) return [];
        const numeroCuotaArrastre = Number(next?.numero_cuota || 0);
        const { mensualidadPagada, mensualidadEnDetalle } = this.esCuotaPagadaOEnDetalle(numeroCuotaArrastre);
        // Mostrar tabla si la mensualidad está pagada O en detalle
        if (!mensualidadPagada && !mensualidadEnDetalle) return [];
        const asignacionesNormal: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen.asignaciones : [];
        const asignNormal = asignacionesNormal.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuotaArrastre);
        if (!asignNormal) return [];
        const idAsignNormal = Number(asignNormal?.id_asignacion_costo || 0);
        return list.filter((m: any) => {
          const estado = (m?.estado || '').toString().toUpperCase();
          if (!(estado === 'PENDIENTE' || estado === 'CONGELADA_PRORROGA' || estado === 'CERRADA_SIN_CUOTA')) return false;
          const idAsignCostoMora = Number(m?.id_asignacion_costo || 0);
          return idAsignCostoMora === idAsignNormal;
        });
      }

      // Para mensualidad: verificar si hay arrastre en el detalle
      const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        return tipoPago === 'ARRASTRE';
      });

      if (hayArrastreEnDetalle) {
        // Si hay arrastre en detalle, buscar la mora vinculada a ese arrastre
        const itemArrastre = (this.detalleFactura || []).find((item: any) => {
          const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
          return tipoPago === 'ARRASTRE';
        });

        if (itemArrastre) {
          const numeroCuotaArrastre = Number(itemArrastre?.numero_cuota || 0);
          const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
          const asignArrastre = asignacionesArrastre.find((a: any) => {
            return Number(a?.numero_cuota || 0) === numeroCuotaArrastre;
          });

          if (asignArrastre) {
            const idAsignArrastre = Number(asignArrastre?.id_asignacion_costo || 0);
            // Retornar solo la mora vinculada a este arrastre
            return list.filter((m: any) => {
              const estado = (m?.estado || '').toString().toUpperCase();
              if (!(estado === 'PENDIENTE' || estado === 'CONGELADA_PRORROGA' || estado === 'CERRADA_SIN_CUOTA')) return false;
              const moraIdVinculada = Number(m?.id_asignacion_vinculada || 0);
              return moraIdVinculada === idAsignArrastre;
            });
          }
        }
      }

      // Verificar si hay arrastres cobrados (no en detalle) para las cuotas que se van a pagar
      const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
      const cuotasAPagar = this.getOrderedCuotasRestantes().slice(0, cant);

      if (cuotasAPagar.length > 0) {
        const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
        const morasVinculadasArrastreCobrado: any[] = [];

        cuotasAPagar.forEach((cuota: any) => {
          const numCuota = Number(cuota?.numero || 0);
          const arrastreCuota = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === numCuota);

          if (arrastreCuota) {
            const arrastrePagado = (arrastreCuota?.estado_pago || '').toString().toUpperCase() === 'COBRADO';

            if (arrastrePagado) {
              // Si el arrastre está cobrado, buscar la mora vinculada a ese arrastre
              const idAsignArrastre = Number(arrastreCuota?.id_asignacion_costo || 0);
              const moraVinculada = list.find((m: any) => {
                const estado = (m?.estado || '').toString().toUpperCase();
                if (!(estado === 'PENDIENTE' || estado === 'CONGELADA_PRORROGA' || estado === 'CERRADA_SIN_CUOTA')) return false;
                const moraIdVinculada = Number(m?.id_asignacion_vinculada || 0);
                return moraIdVinculada === idAsignArrastre;
              });

              if (moraVinculada) {
                morasVinculadasArrastreCobrado.push(moraVinculada);
              }
            }
          }
        });

        if (morasVinculadasArrastreCobrado.length > 0) {
          return morasVinculadasArrastreCobrado;
        }
      }

      const asignacionesNormal: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen.asignaciones : [];
      const normalAsignIds = new Set<number>((asignacionesNormal || []).map((a: any) => Number(a?.id_asignacion_costo || 0)).filter((n: number) => n > 0));
      return list.filter((m: any) => {
        const estado = (m?.estado || '').toString().toUpperCase();
        if (!(estado === 'PENDIENTE' || estado === 'CONGELADA_PRORROGA' || estado === 'CERRADA_SIN_CUOTA')) return false;
        const idAsignCostoMora = Number(m?.id_asignacion_costo || 0);
        const idAsignVinculada = Number(m?.id_asignacion_vinculada || 0);
        if (idAsignVinculada > 0) return false;
        return normalAsignIds.size === 0 ? true : normalAsignIds.has(idAsignCostoMora);
      });
    } catch {
      return [];
    }
  }

  get showMorasTabla(): boolean {
    return this.morasTabla.length > 0;
  }

  getMoraFechaInicio(m: any): Date | null {
    return this.parseDateLoose(m?.fecha_inicio_mora ?? m?.fecha_inicio ?? m?.inicio_mora ?? null);
  }

  getMoraFechaFin(m: any): Date | null {
    return this.parseDateLoose(m?.fecha_fin_mora ?? m?.fecha_fin ?? m?.fin_mora ?? null);
  }

  getMoraDias(m: any): number {
    try {
      const ini = this.getMoraFechaInicio(m);
      if (!ini) return 0;
      // Días mora = días transcurridos desde fecha_inicio hasta HOY (fecha de consulta), no hasta fecha_fin.
      const hoy = new Date();
      hoy.setHours(0, 0, 0, 0);
      const iniLocal = new Date(ini.getTime());
      iniLocal.setHours(0, 0, 0, 0);
      const ms = hoy.getTime() - iniLocal.getTime();
      const days = Math.floor(ms / (1000 * 60 * 60 * 24));
      // Inclusivo: si ini=2026-02-01 y hoy=2026-02-20 => 20 días
      const inclusive = days + 1;
      return inclusive > 0 ? inclusive : 0;
    } catch {
      return 0;
    }
  }

  getMoraTieneProrroga(m: any): boolean {
    try {
      if (!!m?.tiene_prorroga) return true;
      if (!!m?.prorroga) return true;
      const estado = (m?.estado || '').toString().toUpperCase();
      return estado === 'CONGELADA_PRORROGA';
    } catch {
      return false;
    }
  }

  getMoraMontoDescuento(m: any): number {
    try {
      return Number(m?.monto_descuento ?? m?.descuento ?? 0) || 0;
    } catch {
      return 0;
    }
  }

  getMoraMontoMora(m: any): number {
    try {
      return Number(m?.monto_mora ?? 0) || 0;
    } catch {
      return 0;
    }
  }

  getMoraMensualidadLabel(m: any): string {
    try {
      const idAsignCostoMora = Number(m?.id_asignacion_costo || 0);
      const asignacionesNormal: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen.asignaciones : [];
      const asign = asignacionesNormal.find((a: any) => Number(a?.id_asignacion_costo || 0) === idAsignCostoMora) || null;
      const numeroCuota = Number(asign?.numero_cuota || m?.numero_cuota || 0);
      if (!numeroCuota) return '-';
      const mes = this.getMesNombreByCuota(numeroCuota);
      return mes ? `Cuota ${numeroCuota} (${mes})` : `Cuota ${numeroCuota}`;
    } catch {
      return '-';
    }
  }

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
      metodo_pago: ['', [Validators.required]],
      cantidad: [1, [Validators.min(1)]],
      costo_total: [{ value: 0, disabled: true }],
      descuento: [{ value: 0, disabled: true }],
      observaciones: [''],
      comprobante: ['FACTURA', [Validators.required]], // FACTURA | RECIBO (siempre seleccionado)
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
      nro_deposito: [''],
      // Campos para TRASPASO
      traspaso_gestion: [''],
      traspaso_nro_cuota: [''],
      traspaso_cod_est: [''],
      traspaso_fecha_origen: [''],
      traspaso_carrera_origen: [''],
      traspaso_documento: [''],
    });
  }

  // Método auxiliar para buscar moras SIN verificar si el par está completo.
  // Se usa cuando se está armando el detalle y el par mensualidad-arrastre se completa en ese momento.
  private getMorasPendientesSinVerificarPar(idAsignacionCosto: any, tipoActual: 'mensualidad' | 'arrastre', numeroCuota?: number): any[] {
    try {
      const id = Number(idAsignacionCosto || 0);
      if (!id) return [];

      const list: any[] = Array.isArray(this.morasPendientes) ? this.morasPendientes : [];

      if (tipoActual === 'arrastre') {
        const out: any[] = [];
        const seen = new Set<number>();

        const vinculadas = list.filter((m: any) => {
          const moraIdVinculada = Number(m?.id_asignacion_vinculada || 0);
          const estado = (m?.estado || '').toString().toUpperCase();
          return moraIdVinculada === id && this.isMoraEstadoPendiente(estado);
        });

        for (const mora of vinculadas) {
          const idMora = Number(mora?.id_asignacion_mora || 0);
          if (idMora > 0 && seen.has(idMora)) continue;
          if (idMora > 0) seen.add(idMora);
          out.push(mora);
        }

        // Fallback: si no existe vínculo explícito, buscar por cuota normal equivalente.
        if (Number(numeroCuota || 0) > 0) {
          const asignacionesNormal: any[] = Array.isArray(this.resumen?.asignaciones) ? this.resumen.asignaciones : [];
          const asignNormal = asignacionesNormal.find((a: any) => Number(a?.numero_cuota || 0) === Number(numeroCuota || 0));
          const idAsignNormal = Number(asignNormal?.id_asignacion_costo || 0);
          if (idAsignNormal > 0) {
            const porCuotaNormal = list.filter((m: any) => {
              const idMoraAsign = Number(m?.id_asignacion_costo || 0);
              const estado = (m?.estado || '').toString().toUpperCase();
              return idMoraAsign === idAsignNormal && this.isMoraEstadoPendiente(estado);
            });
            for (const mora of porCuotaNormal) {
              const idMora = Number(mora?.id_asignacion_mora || 0);
              if (idMora > 0 && seen.has(idMora)) continue;
              if (idMora > 0) seen.add(idMora);
              out.push(mora);
            }
          }
        }

        return out;
      }

      return list.filter((m: any) => {
        const idMoraAsign = Number(m?.id_asignacion_costo || 0);
        const estado = (m?.estado || '').toString().toUpperCase();
        return idMoraAsign === id && this.isMoraEstadoPendiente(estado);
      });
    } catch (e) {
      console.error('[MensualidadModal] Error en getMorasPendientesSinVerificarPar:', e);
      return [];
    }
  }

  private getMoraPendienteSinVerificarPar(idAsignacionCosto: any, tipoActual: 'mensualidad' | 'arrastre', numeroCuota?: number): any {
    try {
      const moras = this.getMorasPendientesSinVerificarPar(idAsignacionCosto, tipoActual, numeroCuota);
      if (!moras.length) return null;
      const positivas = moras.filter((m: any) => this.recalcularMoraConFechaDeposito(m) > 0);
      if (positivas.length) return positivas[0];
      return moras[0] || null;
    } catch {
      return null;
    }
  }

  private getMorasPendientesByAsign(idAsignacionCosto: any, numeroCuota?: number): any[] {
    try {
      const id = Number(idAsignacionCosto || 0);
      if (!id) return [];

      if (this.tipo === 'arrastre') {
        const moras = this.getMorasPendientesSinVerificarPar(id, 'arrastre', numeroCuota);
        if (!moras.length) return [];
        if (numeroCuota !== undefined) {
          const parCompleto = this.parMensualidadArrastreCompleto(numeroCuota, 'arrastre');
          if (!parCompleto) return [];
        }
        return moras;
      }

      const moras = this.getMorasPendientesSinVerificarPar(id, 'mensualidad', numeroCuota);
      if (!moras.length) return [];

      if (numeroCuota !== undefined) {
        const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
        const arrastreCuota = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuota);

        if (arrastreCuota) {
          const arrastrePagado = (arrastreCuota?.estado_pago || '').toString().toUpperCase() === 'COBRADO';
          if (arrastrePagado) return moras;

          const parCompleto = this.parMensualidadArrastreCompleto(numeroCuota, 'mensualidad');
          if (!parCompleto) return [];
        }
      }

      return moras;
    } catch {
      return [];
    }
  }

  private getMoraPendienteByAsign(idAsignacionCosto: any, numeroCuota?: number): any {
    try {
      console.log('[MensualidadModal] getMoraPendienteByAsign - INICIO');
      console.log('[MensualidadModal] idAsignacionCosto recibido:', idAsignacionCosto);
      console.log('[MensualidadModal] numeroCuota recibido:', numeroCuota);
      console.log('[MensualidadModal] tipo:', this.tipo);

      const moras = this.getMorasPendientesByAsign(idAsignacionCosto, numeroCuota);
      console.log('[MensualidadModal] Moras encontradas:', moras);
      if (!moras.length) return null;
      const positivas = moras.filter((m: any) => this.recalcularMoraConFechaDeposito(m) > 0);
      if (positivas.length) return positivas[0];
      return moras[0] || null;
    } catch (e) {
      console.error('[MensualidadModal] Error en getMoraPendienteByAsign:', e);
      return null;
    }
  }

  private getMoraNetoFromRow(mora: any): number {
    try {
      // Calcular saldo pendiente de la mora:
      // monto_mora (monto acumulado) - monto_descuento - monto_pagado (pagos parciales previos)
      const montoMora = Number(mora?.monto_mora || 0);
      const desc = Number(mora?.monto_descuento || 0);
      const montoPagado = Number(mora?.monto_pagado || 0);
      return Math.max(0, montoMora - desc - montoPagado);
    } catch { return 0; }
  }

  /**
   * Recalcula la mora basándose en la fecha de depósito para métodos de pago bancarios.
   * Si el método de pago requiere fecha_deposito (tarjeta, cheque, transferencia, depósito)
   * y la fecha es anterior a hoy, recalcula la mora solo hasta esa fecha.
   */
  private recalcularMoraConFechaDeposito(mora: any): number {
    try {
      // Verificar si el método de pago requiere fecha de depósito
      const requiereFechaDeposito = this.isTarjeta || this.isCheque || this.isTransferenciaBancaria || this.isDeposito;
      if (!requiereFechaDeposito) {
        // Si no es un método bancario, usar el cálculo normal
        return this.getMoraNetoFromRow(mora);
      }

      const fechaDepositoStr = this.form.get('fecha_deposito')?.value;
      if (!fechaDepositoStr) {
        // Si no hay fecha de depósito ingresada, usar el cálculo normal
        return this.getMoraNetoFromRow(mora);
      }

      // Parsear fechas
      const fechaDeposito = this.parseDateLoose(fechaDepositoStr);
      const fechaInicioMora = this.parseDateLoose(mora?.fecha_inicio_mora ?? mora?.fecha_inicio_mora ?? mora?.fecha_inicio ?? null);
      const hoy = new Date();
      hoy.setHours(0, 0, 0, 0);
      if (fechaDeposito) fechaDeposito.setHours(0, 0, 0, 0);

      if (!fechaInicioMora || !fechaDeposito) {
        return this.getMoraNetoFromRow(mora);
      }
      fechaInicioMora.setHours(0, 0, 0, 0);

      // Si la fecha de depósito es mayor o igual a hoy, usar el cálculo normal
      if (fechaDeposito >= hoy) {
        return this.getMoraNetoFromRow(mora);
      }

      // Si la fecha de depósito es anterior a la fecha de inicio de mora, mora = 0
      if (fechaDeposito < fechaInicioMora) {
        return 0;
      }

      // Recalcular mora: días desde fecha_inicio_mora hasta fecha_deposito
      const montoBase = Number(mora?.monto_base || 0);
      const desc = Number(mora?.monto_descuento || 0);
      const montoPagado = Number(mora?.monto_pagado || 0);

      // Calcular días transcurridos desde inicio de mora hasta fecha de depósito
      const diffTime = fechaDeposito.getTime() - fechaInicioMora.getTime();
      const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 para incluir el día de inicio

      // Calcular mora recalculada
      const moraRecalculada = montoBase * Math.max(0, diffDays);

      // Restar descuentos y pagos parciales previos
      const moraFinal = Math.max(0, moraRecalculada - desc - montoPagado);

      console.log('[MensualidadModal] Recálculo de mora con fecha_deposito:');
      console.log('  - Fecha inicio mora:', fechaInicioMora.toISOString().split('T')[0]);
      console.log('  - Fecha depósito:', fechaDeposito.toISOString().split('T')[0]);
      console.log('  - Días transcurridos:', diffDays);
      console.log('  - Monto base diario:', montoBase);
      console.log('  - Mora original:', mora?.monto_mora);
      console.log('  - Mora recalculada:', moraRecalculada);
      console.log('  - Mora final (después de desc/pagos):', moraFinal);

      return moraFinal;
    } catch (e) {
      console.error('[MensualidadModal] Error recalculando mora con fecha_deposito:', e);
      return this.getMoraNetoFromRow(mora);
    }
  }

  /**
   * Verifica si una cuota tiene su par mensualidad-arrastre completo.
   * Solo se debe cobrar mora cuando AMBOS (mensualidad Y arrastre) de la misma cuota están pagados o en detalle.
   *
   * @param numeroCuota - Número de cuota a verificar
   * @param tipoActual - Tipo actual que se está pagando ('mensualidad' o 'arrastre')
   * @returns true si el par está completo (ambos pagados/en detalle), false si falta uno
   */
  private parMensualidadArrastreCompleto(numeroCuota: number, tipoActual: string): boolean {
    try {
      console.log('[MensualidadModal] parMensualidadArrastreCompleto - numeroCuota:', numeroCuota, 'tipoActual:', tipoActual);

      // Verificar si hay asignaciones de arrastre (si no hay, no aplica esta lógica)
      const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
      if (asignacionesArrastre.length === 0) {
        console.log('[MensualidadModal] No hay asignaciones de arrastre, retornando true');
        return true; // No hay arrastre, no aplica la lógica de pares
      }

      // Buscar si existe arrastre para esta cuota
      const arrastreCuota = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuota);
      if (!arrastreCuota) {
        console.log('[MensualidadModal] No hay arrastre para esta cuota, retornando true');
        return true; // No hay arrastre para esta cuota específica
      }

      // Verificar estado de la mensualidad
      const asignaciones = this.resumen?.asignaciones || [];
      const mensualidadCuota = asignaciones.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuota);
      const mensualidadPagada = mensualidadCuota && (mensualidadCuota?.estado_pago || '').toString().toUpperCase() === 'COBRADO';
      const mensualidadEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        const numCuota = Number(item?.numero_cuota || 0);
        return tipoPago === 'MENSUALIDAD' && numCuota === numeroCuota;
      });

      // Verificar estado del arrastre
      const arrastrePagado = (arrastreCuota?.estado_pago || '').toString().toUpperCase() === 'COBRADO';
      const arrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        const numCuota = Number(item?.numero_cuota || 0);
        return tipoPago === 'ARRASTRE' && numCuota === numeroCuota;
      });

      console.log('[MensualidadModal] Estado del par:', {
        mensualidadPagada,
        mensualidadEnDetalle,
        arrastrePagado,
        arrastreEnDetalle
      });

      // El par está completo si AMBOS están pagados o en detalle
      const mensualidadCompleta = mensualidadPagada || mensualidadEnDetalle;
      const arrastreCompleto = arrastrePagado || arrastreEnDetalle;

      const parCompleto = mensualidadCompleta && arrastreCompleto;
      console.log('[MensualidadModal] Par completo:', parCompleto);

      return parCompleto;
    } catch (e) {
      console.error('[MensualidadModal] Error en parMensualidadArrastreCompleto:', e);
      return true; // En caso de error, permitir cobrar mora
    }
  }

  private tieneArrastrePendiente(): boolean {
    try {
      // Primero verificar si ya hay items de arrastre en el detalle de factura
      const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        return tipoPago === 'ARRASTRE';
      });

      // Si ya hay arrastre en el detalle, considerar que NO hay arrastre pendiente
      if (hayArrastreEnDetalle) {
        return false;
      }

      // Si no hay en el detalle, verificar en el resumen
      const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
      return asignacionesArrastre.some((a: any) => {
        const estadoPago = (a?.estado_pago || '').toString().toUpperCase();
        return estadoPago !== 'COBRADO';
      });
    } catch { return false; }
  }

  private tieneMensualidadPendiente(): boolean {
    try {
      console.log('[MensualidadModal] tieneMensualidadPendiente - INICIO');
      console.log('[MensualidadModal] detalleFactura:', this.detalleFactura);

      // Primero verificar si ya hay items de mensualidad en el detalle de factura
      const hayMensualidadEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        console.log('[MensualidadModal] Item en detalle - tipoPago:', tipoPago);
        return tipoPago === 'MENSUALIDAD';
      });

      console.log('[MensualidadModal] hayMensualidadEnDetalle:', hayMensualidadEnDetalle);

      // Si ya hay mensualidad en el detalle, considerar que NO hay mensualidad pendiente
      if (hayMensualidadEnDetalle) {
        console.log('[MensualidadModal] Retornando FALSE porque hay mensualidad en detalle');
        return false;
      }

      // Si no hay en el detalle, verificar en el resumen
      const cuotasRestantes = this.getOrderedCuotasRestantes();
      console.log('[MensualidadModal] cuotasRestantes.length:', cuotasRestantes.length);
      const resultado = cuotasRestantes.length > 0;
      console.log('[MensualidadModal] tieneMensualidadPendiente - RESULTADO:', resultado);
      return resultado;
    } catch (e) {
      console.error('[MensualidadModal] Error en tieneMensualidadPendiente:', e);
      return false;
    }
  }

  getMoraNetoPublic(mora: any): number {
    return this.recalcularMoraConFechaDeposito(mora);
  }

  private shouldCobrarMoraForPago(montoPago: number, numeroCuota: number | null, idAsignacionCosto: any): boolean {
    try {
      if (!idAsignacionCosto) return false;
      if (!numeroCuota) return false;
      const restante = this.getCuotaRestanteByNumero(numeroCuota);
      if (!(restante > 0)) return false;
      return Number(montoPago || 0) >= (restante - 0.0001);
    } catch { return false; }
  }

  private buildPagoMoraItem(mora: any, hoy: any, compSel: string, tipo_documento: string, medio_doc: string): any {
    console.log('[MensualidadModal] ===== buildPagoMoraItem INICIO =====');
    console.log('[MensualidadModal] mora recibida:', mora);

    // Calcular el monto neto (con descuento restado) para el campo 'monto'
    const neto = this.recalcularMoraConFechaDeposito(mora);
    // Para pu_mensualidad, usar el monto_mora ORIGINAL (sin restar descuento)
    const montoMoraOriginal = Number(mora?.monto_mora || 0);
    const numeroCuota = Number(mora?.numero_cuota || 0);
    const mesNombre = numeroCuota > 0 ? this.getMesNombreByCuota(numeroCuota) : null;

    console.log('[MensualidadModal] numeroCuota:', numeroCuota);
    console.log('[MensualidadModal] mesNombre:', mesNombre);

    const detalleArrastre = mesNombre
      ? `Nivelación (${mesNombre})`
      : `Nivelación Cuota ${numeroCuota || ''}`;

    const detalleMora = mesNombre
      ? `Mens. (${mesNombre}) Niv`
      : `Mens. Cuota ${numeroCuota || ''} Niv`;

    console.log('[MensualidadModal] detalleMora construido:', detalleMora);

    // Para mora, construir observaciones sin el flag [Arrastre]
    const obs = (this.form.get('observaciones')?.value || '').toString().trim();

    const moraItem = {
      id_forma_cobro: this.form.get('metodo_pago')?.value || null,
      nro_cobro: null,
      monto: neto,
      fecha_cobro: hoy,
      observaciones: obs,
      pu_mensualidad: montoMoraOriginal,
      detalle: detalleMora.trim(),
      tipo_pago: 'MORA',
      cod_tipo_cobro: 'NIVELACION',
      id_asignacion_mora: Number(mora?.id_asignacion_mora || 0) || null,
      // IMPORTANTE: NO enviar id_asignacion_costo ni id_cuota para items de mora
      // El cobro de mora debe registrarse SOLO con id_asignacion_mora
      id_asignacion_costo: null,
      id_cuota: null,
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
      traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
      traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
      traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
      traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
      traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
      traspaso_documento: this.form.get('traspaso_documento')?.value || null,
      ...this.buildTraspasoComputedFields(),
      descuento: Number(mora?.monto_descuento || 0) || 0,
    };

    console.log('[MensualidadModal] moraItem final:', moraItem);
    console.log('[MensualidadModal] ===== buildPagoMoraItem FIN =====');

    return moraItem;
  }

  // Obtener monto de mora pendiente (usado para cálculos internos)
  private getMoraPendienteMonto(): number {
    try {
      return this.moraDisplay;
    } catch {
      return 0;
    }
  }

  // Mora a mostrar en el modal según la selección actual
  get moraDisplay(): number {
    try {
      if (!(this.tipo === 'mensualidad' || this.tipo === 'arrastre')) return 0;

      if (this.tipo === 'arrastre') {
        console.log('[MensualidadModal] moraDisplay - ARRASTRE');

        const next: any = this.resumen?.arrastre?.next_cuota || null;
        console.log('[MensualidadModal] next_cuota:', next);

        const numeroCuotaArrastre = Number(next?.numero_cuota || 0);
        console.log('[MensualidadModal] numeroCuotaArrastre:', numeroCuotaArrastre);

        // Para arrastre: NO mostrar mora si la mensualidad de esta cuota NO está pagada ni en detalle
        // La mora solo se muestra cuando la mensualidad está pagada o en el detalle
        const { mensualidadPagada, mensualidadEnDetalle } = this.esCuotaPagadaOEnDetalle(numeroCuotaArrastre);
        console.log('[MensualidadModal] mensualidadPagada:', mensualidadPagada, 'mensualidadEnDetalle:', mensualidadEnDetalle);

        if (!mensualidadPagada && !mensualidadEnDetalle) {
          console.log('[MensualidadModal] Retornando 0 porque la mensualidad NO está pagada ni en detalle');
          return 0;
        }

        const esParcial = !!this.form.get('pago_parcial')?.value;
        let moraValue = 0;

        if (esParcial) {
          const idAsign = next ? (next?.id_asignacion_costo ?? null) : null;
          const moras = this.getMorasPendientesSinVerificarPar(idAsign, 'arrastre', numeroCuotaArrastre);
          moraValue = moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
        } else {
          const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
          const cuotas = this.getOrderedCuotasRestantes().slice(0, cant).filter((it: any) => !it.esMoraOrfana);
          for (const cuota of cuotas) {
            const numeroCuota = Number(cuota?.numero || 0);
            if (!numeroCuota) continue;
            const pagada = this.esCuotaPagadaOEnDetalle(numeroCuota);
            if (!pagada.mensualidadPagada && !pagada.mensualidadEnDetalle) continue;

            const moras = this.getMorasPendientesSinVerificarPar(cuota?.id_asignacion_costo ?? null, 'arrastre', numeroCuota);
            moraValue += moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
          }
        }

        console.log('[MensualidadModal] Valor de mora:', moraValue);

        return moraValue;
      }

      // Si hay arrastre en el detalle de factura, buscar la mora de inscripción NORMAL
      // La mora de inscripción NORMAL tiene id_asignacion_vinculada apuntando al arrastre
      const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        return tipoPago === 'ARRASTRE';
      });

      if (hayArrastreEnDetalle) {
        // Buscar el item de arrastre en el detalle para obtener su numero_cuota
        const itemArrastre = (this.detalleFactura || []).find((item: any) => {
          const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
          return tipoPago === 'ARRASTRE';
        });

        if (itemArrastre) {
          const numeroCuotaArrastre = Number(itemArrastre?.numero_cuota || 0);
          // Buscar la asignación de arrastre correspondiente
          const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
          const asignArrastre = asignacionesArrastre.find((a: any) => {
            return Number(a?.numero_cuota || 0) === numeroCuotaArrastre;
          });

          if (asignArrastre) {
            const idAsignArrastre = asignArrastre?.id_asignacion_costo ?? null;
            const morasNormal = this.getMorasPendientesSinVerificarPar(idAsignArrastre, 'arrastre', numeroCuotaArrastre);
            return morasNormal.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
          }
        }
      }

      const esParcial = !!this.form.get('pago_parcial')?.value;
      if (esParcial) {
        const start = this.getStartCuotaFromResumen();
        const list = this.getOrderedCuotasRestantes();
        const first = list.find(it => Number(it.numero) === Number(start)) || list[0] || null;
        const idAsign = first ? (first.id_asignacion_costo ?? null) : null;
        const numeroCuota = first ? Number(first.numero || 0) : 0;
        const moras = this.getMorasPendientesByAsign(idAsign, numeroCuota);
        return moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
      }

      const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
      const list = this.getOrderedCuotasRestantes().slice(0, cant);
      let acc = 0;
      for (const it of list) {
        const numeroCuota = Number(it?.numero || 0);
        const moras = this.getMorasPendientesByAsign(it?.id_asignacion_costo ?? null, numeroCuota);
        acc += moras.reduce((sum: number, m: any) => sum + this.recalcularMoraConFechaDeposito(m), 0);
      }
      return acc;
    } catch { return 0; }
  }

  // Suma del MONTO BRUTO de las próximas k cuotas (para mostrar en Precio Unitario)
  private sumBrutoMenosPagadoNextK(k: number): number {
    try {
      // Excluir moras huérfanas: el PU muestra el precio de cuotas regulares, no de moras
      const list = this.getOrderedCuotasRestantes().filter(it => !it.esMoraOrfana);
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
      // it.restante es el monto correcto para todos los tipos:
      // - cuotas regulares: montoNeto - montoPagado
      // - moras huérfanas: monto restante de la mora
      let acc = 0; let c = 0;
      for (const it of list) {
        acc += Math.max(0, Number(it.restante || 0));
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

        if (cantSel === 5 && this.validarCuotasSinDescuentoPrevio(cantSel)) {
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
      // Mostrar el precio unitario (para 1 sola cuota) independientemente de la cantidad seleccionada
      return this.sumBrutoMenosPagadoNextK(1);
    } catch { return Number(this.pu || 0); }
  }

  // Máximo permitido para pago parcial: monto - descuento - monto_pagado - lo que ya está en tabla detalle
  getParcialMax(): number {
    try {
      const start = this.getStartCuotaFromResumen();
      // Para arrastre, buscar en asignaciones_arrastre; para mensualidad, en asignaciones
      const asignaciones = this.tipo === 'arrastre'
        ? (this.resumen?.asignaciones_arrastre || [])
        : (this.resumen?.asignaciones || []);
      const cuota = asignaciones.find((a: any) => Number(a?.numero_cuota) === start);

      if (!cuota) {
        return Math.max(1, Math.floor(Number(this.puDisplay || 0)) || 0);
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

      // Para arrastre, agregar la mora pendiente al máximo
      if (this.tipo === 'arrastre') {
        const idAsignacionCosto = Number(cuota?.id_asignacion_costo || 0);
        const numeroCuota = Number(cuota?.numero_cuota || 0);
        if (idAsignacionCosto > 0) {
          const mora = this.getMoraPendienteByAsign(idAsignacionCosto, numeroCuota);
          if (mora) {
            const moraNeto = this.recalcularMoraConFechaDeposito(mora);
            deudaReal += moraNeto;
          }
        }
      }

      return (isNaN(deudaReal) || deudaReal <= 0)
        ? Math.max(1, Math.floor(Number(this.puDisplay || 0)) || 0)
        : deudaReal;
    } catch {
      return Math.max(1, Math.floor(Number(this.puDisplay || 0)) || 0);
    }
  }

  get montoParcialMax(): number | null {
    try {
      if (!this.form?.get('pago_parcial')?.value) return null;
      if (this.moraPendienteDetectada) {
        return Math.floor(this.recalcularMoraConFechaDeposito(this.moraPendienteDetectada) || 0) || null;
      }
      if (this.tipo === 'reincorporacion') {
        return Math.floor(Number(this.puDisplay || 0)) || null;
      }
      if (this.tipo === 'mora') {
        return Math.floor(Number(this.puDisplay || 0)) || null;
      }
      return Math.floor(this.getParcialMax() || 0) || null;
    } catch {
      return null;
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
    }
    return true;
  }

  ngOnInit(): void {
    console.log('[MensualidadModal] ngOnInit ejecutado');

    // Inicializar estado del botón
    this.actualizarEstadoBoton();

    // Cargar parámetros de descuento de semestre completo y definiciones de descuentos
    this.cargarParametrosDescuentoSemestre();
    this.cargarDefinicionesDescuentos();
    console.log('[MensualidadModal] Cache inicial:', this.defDescuentosCache.length);
    this.recalcTotal();

    // Recalcular total al cambiar cantidad, descuento o monto_manual
    this.form.get('cantidad')?.valueChanges.subscribe(() => {
      this.recalcTotal();
      this.updateDescuentoDisplay();
      this.verificarOrdenPago();
    });
    this.form.get('monto_manual')?.valueChanges.subscribe(() => this.recalcTotal());

    // Recalcular total al cambiar monto parcial (sin modificar el campo descuento)
    this.form.get('monto_parcial')?.valueChanges.subscribe((value: any) => {
      // Redondear automáticamente a entero si se ingresa un decimal
      if (value && !Number.isInteger(Number(value))) {
        const rounded = Math.round(Number(value));
        this.form.get('monto_parcial')?.setValue(rounded, { emitEvent: false });
      }
      this.recalcTotal();
    });
    // Cambios de método de pago para activar validadores de TARJETA y recalcular mora
    this.form.get('metodo_pago')?.valueChanges.subscribe(() => {
      this.updateTarjetaValidators();
      this.recalcTotal();
    });
    this.updateTarjetaValidators();

    // Recalcular mora cuando cambie la fecha de depósito
    this.form.get('fecha_deposito')?.valueChanges.subscribe(() => {
      this.recalcTotal();
    });

    // Alternar validadores/estado para pago parcial
    this.form.get('pago_parcial')?.valueChanges.subscribe((on: boolean) => {
      // Pago parcial permitido en 'mensualidad', 'arrastre' y 'reincorporacion'
      if (this.tipo !== 'mensualidad' && this.tipo !== 'arrastre' && this.tipo !== 'reincorporacion') {
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

        // Determinar el máximo según si es pago de mora o pago normal
        let maxParcial = 0;
        if (this.moraPendienteDetectada) {
          // Para mora: usar monto_base (monto original) como máximo
          // Esto permite al usuario pagar hasta el monto completo, independientemente de pagos parciales anteriores
          maxParcial = Math.floor(this.recalcularMoraConFechaDeposito(this.moraPendienteDetectada));
        } else {
          maxParcial = Math.floor(this.getParcialMax() || 0);
        }

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

  private actualizarEstadoBoton(): void {
    const opciones = this.getCantidadOptions();
    this.botonDeshabilitado = opciones.length === 0;
    console.log('[MensualidadModal] actualizarEstadoBoton - opciones.length:', opciones.length, 'botonDeshabilitado:', this.botonDeshabilitado);
  }

  ngAfterViewInit(): void {
    console.log('[MensualidadModal] ngAfterViewInit ejecutado');
    // Actualizar el estado del botón después de que la vista se haya inicializado completamente
    // Usar setTimeout para asegurar que Angular haya completado la detección de cambios
    setTimeout(() => {
      this.actualizarEstadoBoton();
    }, 0);

    this.bindComprobanteDefaultOnModalOpen();
  }

  private bindComprobanteDefaultOnModalOpen(): void {
    if (this.modalOpenListenerBound) return;
    const modalEl = document.getElementById('mensualidadModal');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', () => {
      // Reaplicar selección por defecto cada vez que se abre el modal.
      // FACTURA por defecto; RECIBO solo cuando FACTURA está bloqueada por descuentos institucionales.
      setTimeout(() => this.applyDefaultComprobanteOnOpen(), 0);
    });

    this.modalOpenListenerBound = true;
  }

  private applyDefaultComprobanteOnOpen(): void {
    const ctrl = this.form.get('comprobante');
    if (!ctrl) return;

    const facturaBloqueada = this.facturaDeshabilitada;
    const comprobanteDefault = facturaBloqueada ? 'RECIBO' : 'FACTURA';
    if (ctrl.value !== comprobanteDefault) {
      this.form.patchValue({ comprobante: comprobanteDefault }, { emitEvent: false });
    }
  }

  ngOnChanges(changes: SimpleChanges): void {
    console.log('[MensualidadModal] ngOnChanges ejecutado', changes);

    // Actualizar estado del botón cuando cambian inputs relevantes
    if (changes['detalleFactura'] || changes['pendientes'] || changes['tipo'] || changes['resumen'] || changes['startCuotaOverride']) {
      this.actualizarEstadoBoton();
    }

    if (changes['defaultMetodoPago']) {
      const v = (this.defaultMetodoPago || '').toString();
      if (v) {
        this.form.patchValue({ metodo_pago: v }, { emitEvent: false });
        this.updateTarjetaValidators();
        this.recalcTotal();
      }
    }
    if (changes['pendientes'] || changes['pu'] || changes['tipo'] || changes['resumen'] || changes['startCuotaOverride'] || changes['morasPendientes']) {
      // Si cambia a un tipo distinto de mensualidad o arrastre, forzar pago_parcial=false
      if (changes['tipo'] && this.tipo !== 'mensualidad' && this.tipo !== 'arrastre') {
        this.form.patchValue({ pago_parcial: false }, { emitEvent: false });
      }
      this.configureByTipo();
      this.verificarOrdenPago();
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
      } catch { }
      this.recalcTotal();
      // Si el parcial está activo, actualizar tope y valor sugerido del monto parcial con el PU efectivo
      if (this.tipo === 'mensualidad' && this.form.get('pago_parcial')?.value) {
        const maxParcial = Math.floor(this.getParcialMax() || 0);
        this.form.get('monto_parcial')?.setValidators([Validators.required, Validators.min(1), Validators.max(Number(maxParcial || Number.MAX_SAFE_INTEGER))]);
        this.form.get('monto_parcial')?.setValue(0, { emitEvent: false });
        this.form.get('monto_parcial')?.updateValueAndValidity({ emitEvent: false });
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
      if ((this.tipo === 'mensualidad' || this.tipo === 'arrastre') && this.form.get('pago_parcial')?.value) {
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

        // Si el parcial completa la cuota, sumar mora pendiente
        // Si hay arrastre en el detalle, sumar la mora asociada a esa cuota de arrastre
        // Si NO hay arrastre (ni pendiente ni en detalle), sumar mora de la cuota de mensualidad
        const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
          const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
          return tipoPago === 'ARRASTRE';
        });

        if (hayArrastreEnDetalle) {
          // Buscar la mora de inscripción NORMAL asociada a la cuota de arrastre en el detalle
          try {
            const itemArrastre = (this.detalleFactura || []).find((item: any) => {
              const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
              return tipoPago === 'ARRASTRE';
            });

            if (itemArrastre) {
              const numeroCuotaArrastre = Number(itemArrastre?.numero_cuota || 0);
              const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
              const asignArrastre = asignacionesArrastre.find((a: any) => {
                return Number(a?.numero_cuota || 0) === numeroCuotaArrastre;
              });

              if (asignArrastre) {
                const idAsignArrastre = asignArrastre?.id_asignacion_costo ?? null;
                const morasNormal = this.getMorasPendientesSinVerificarPar(idAsignArrastre, 'arrastre', numeroCuotaArrastre);
                if (this.shouldCobrarMoraForPago(total, numeroCuotaArrastre, idAsignArrastre)) {
                  total += morasNormal.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
                }
              }
            }
          } catch { }
        } else {
          // No hay arrastre en detalle: sumar mora de la cuota de mensualidad
          try {
            const start = this.getStartCuotaFromResumen();
            const list = this.getOrderedCuotasRestantes();
            const first = list.find(it => Number(it.numero) === Number(start)) || list[0] || null;
            const numero_cuota = first ? (Number(first.numero || 0) || null) : null;
            const id_asignacion_costo = first ? (first.id_asignacion_costo ?? null) : null;
            const moras = this.getMorasPendientesByAsign(id_asignacion_costo, numero_cuota || undefined);
            if (this.shouldCobrarMoraForPago(total, numero_cuota, id_asignacion_costo)) {
              total += moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
            }
          } catch { }
        }
      } else {
        const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
        // Usar deuda real: monto - (monto_pagado + descuento)
        const deudaReal = this.sumDeudaRealNextK(cant);
        if (deudaReal > 0) {
          total = deudaReal;
          const totalCuotasPendientes = this.pendientes || 0;

          if (cant === 5 && this.validarCuotasSinDescuentoPrevio(cant)) {
            const descuentoAuto = this.calcularDescuentoSemestreCompleto(cant);
            if (descuentoAuto > 0) {
              total = Math.max(0, total - descuentoAuto);
            }
          }

          // Sumar mora de las cuotas seleccionadas (si existe mora pendiente)
          // Si hay arrastre en el detalle, sumar la mora asociada a esa cuota de arrastre
          // Si NO hay arrastre (ni pendiente ni en detalle), sumar mora de las cuotas de mensualidad
          const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
            const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
            return tipoPago === 'ARRASTRE';
          });

          if (hayArrastreEnDetalle) {
            // Buscar la mora de inscripción NORMAL asociada a la cuota de arrastre en el detalle
            try {
              const itemArrastre = (this.detalleFactura || []).find((item: any) => {
                const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
                return tipoPago === 'ARRASTRE';
              });

              if (itemArrastre) {
                const numeroCuotaArrastre = Number(itemArrastre?.numero_cuota || 0);
                const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
                const asignArrastre = asignacionesArrastre.find((a: any) => {
                  return Number(a?.numero_cuota || 0) === numeroCuotaArrastre;
                });

                if (asignArrastre) {
                  const idAsignArrastre = asignArrastre?.id_asignacion_costo ?? null;
                  const morasNormal = this.getMorasPendientesSinVerificarPar(idAsignArrastre, 'arrastre', numeroCuotaArrastre);
                  total += morasNormal.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
                }
              }
            } catch { }
          } else {
            // No hay arrastre en detalle: sumar mora de las cuotas de mensualidad
            try {
              const list = this.getOrderedCuotasRestantes().slice(0, cant);
              let moraAcc = 0;
              for (const it of list) {
                const numeroCuota = Number(it?.numero || 0);
                const moras = this.getMorasPendientesByAsign(it?.id_asignacion_costo ?? null, numeroCuota);
                moraAcc += moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
              }
              total += moraAcc;
            } catch { }
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
      // Usar restante real de cada ítem (cubre moras huérfanas y cuotas regulares)
      const listArr = this.getOrderedCuotasRestantes().slice(0, cant);
      total = listArr.reduce((acc, it) => acc + Math.max(0, Number(it.restante || 0)), 0);

      // Sumar mora de cada cuota regular SOLO si su mensualidad está pagada o en detalle.
      // Las moras huérfanas ya están contadas en su restante, no agregar extra.
      try {
        const regulares = listArr.filter(it => !it.esMoraOrfana);
        for (const cuota of regulares) {
          const numeroCuotaArrastre = Number(cuota?.numero || 0);
          const estadoCuota = this.esCuotaPagadaOEnDetalle(numeroCuotaArrastre);
          if (!estadoCuota.mensualidadPagada && !estadoCuota.mensualidadEnDetalle) continue;

          const idAsign = cuota?.id_asignacion_costo ?? null;
          const moras = this.getMorasPendientesSinVerificarPar(idAsign, 'arrastre', numeroCuotaArrastre);
          total += moras.reduce((acc: number, m: any) => acc + this.recalcularMoraConFechaDeposito(m), 0);
        }
      } catch { }
    } else if (this.tipo === 'reincorporacion') {
      // Total = monto parcial si aplica, caso contrario = PU (monto de reincorporación)
      if (this.form.get('pago_parcial')?.value) {
        total = Number(this.form.get('monto_parcial')?.value || 0);
      } else {
        total = Number(this.pu || 0);
      }
    } else if (this.tipo === 'mora') {
      // Mora: calcular según cantidad y pago parcial
      if (this.form.get('pago_parcial')?.value) {
        total = Number(this.form.get('monto_parcial')?.value || 0);
      } else {
        const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));
        total = cant * Number(this.pu || 0);
      }
    } else {
      total = Number(this.form.get('monto_manual')?.value || 0);
    }
    this.form.get('costo_total')?.setValue(total, { emitEvent: false });
  }

  // Opciones para el selector de cantidad (1..cuotas_disponibles_reales)
  getCantidadOptions(): number[] {
    // Para mensualidad y arrastre, usar el número real de cuotas disponibles después del filtrado
    if (this.tipo === 'mensualidad' || this.tipo === 'arrastre') {
      const cuotasDisponibles = this.getOrderedCuotasRestantes();
      const maxDisponible = cuotasDisponibles.length;
      console.log('[MensualidadModal] getCantidadOptions - cuotasDisponibles.length:', maxDisponible);
      if (maxDisponible === 0) {
        console.log('[MensualidadModal] getCantidadOptions RETORNA: [] (vacío)');
        return [];
      }
      const opciones = Array.from({ length: maxDisponible }, (_, i) => i + 1);
      console.log('[MensualidadModal] getCantidadOptions RETORNA:', opciones);
      return opciones;
    }
    // Para otros tipos (mora, rezagado, etc.), usar pendientes como antes
    const p = Math.max(0, Number(this.pendientes || 0));
    const opciones = Array.from({ length: p }, (_, i) => i + 1);
    console.log('[MensualidadModal] getCantidadOptions (otros tipos) RETORNA:', opciones, 'pendientes:', this.pendientes);
    return opciones;
  }

  getCantidadLabel(n: number): string {
    // Use direct list indexing so orphan moras and non-sequential cuotas label correctly
    const list = this.getOrderedCuotasRestantes();
    const item = list[Number(n) - 1];
    if (!item) {
      const start = this.getStartCuotaFromResumen();
      const cuota = start + Math.max(0, Number(n || 0)) - 1;
      const mes = this.getMesNombreByCuota(cuota);
      return mes ? `${n} - ${mes}` : `${n}`;
    }
    const mes = this.getMesNombreByCuota(item.numero);
    if (item.esMoraOrfana) {
      return mes ? `${n} - Mora ${mes}` : `${n} - Mora`;
    }
    return mes ? `${n} - ${mes}` : `${n}`;
  }

  // Verifica si hay opciones disponibles para agregar (para deshabilitar el botón cuando el select está vacío)
  get tieneOpcionesDisponibles(): boolean {
    if (this.tipo === 'mensualidad' || this.tipo === 'arrastre') {
      const opciones = this.getCantidadOptions();
      console.log('[MensualidadModal] tieneOpcionesDisponibles - tipo:', this.tipo, 'opciones.length:', opciones.length, 'opciones:', opciones);
      return opciones.length > 0;
    }
    // Para otros tipos, verificar pendientes
    const resultado = (this.pendientes || 0) > 0;
    console.log('[MensualidadModal] tieneOpcionesDisponibles - tipo:', this.tipo, 'pendientes:', this.pendientes, 'resultado:', resultado);
    return resultado;
  }

  private getStartCuotaFromResumen(): number {
    try {
      // Ignorar override si:
      // - Es mensualidad y hay arrastre en el detalle (override viene del modal de arrastre)
      // - Es arrastre y hay mensualidad en el detalle (override viene del modal de mensualidad)
      const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        return tipoPago === 'ARRASTRE';
      });

      const hayMensualidadEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        return tipoPago === 'MENSUALIDAD';
      });

      const ignorarOverride = (this.tipo === 'mensualidad' && hayArrastreEnDetalle) || (this.tipo === 'arrastre' && hayMensualidadEnDetalle);

      // Priorizar override proveniente del padre cuando exista
      // EXCEPTO cuando debemos ignorarlo
      if (this.startCuotaOverride && this.startCuotaOverride > 0 && !ignorarOverride) {
        return Number(this.startCuotaOverride);
      }

      // Para arrastre, usar la primera cuota pendiente real (no mora huérfana) del listado filtrado.
      // NO usar resumen.arrastre.next_cuota porque el backend no filtra estado_pago='COBRADO'.
      if (this.tipo === 'arrastre') {
        const list = this.getOrderedCuotasRestantes();
        const firstRegular = list.find(it => !it.esMoraOrfana);
        const first = Number((firstRegular || list[0])?.numero || 0);
        if (first > 0) return first;
        // Fallback solo si no hay cuotas en la lista
        const next = this.resumen?.arrastre?.next_cuota || null;
        const numeroCuota = Number(next?.numero_cuota || 0);
        if (numeroCuota > 0) return numeroCuota;
      }

      // Para mensualidad, usar la primera cuota real (no mora huérfana)
      const list = this.getOrderedCuotasRestantes();
      const firstRegular = list.find(it => !it.esMoraOrfana);
      const first = Number((firstRegular || list[0])?.numero || 0);
      if (first > 0) return first;
      const next = Number(this.resumen?.mensualidad_next?.next_cuota?.numero_cuota || 0);
      return next > 0 ? next : 1;
    } catch { return 1; }
  }

  private getMesNombreByCuota(numeroCuota: number): string | null {
    try {
      if (this.tipo === 'arrastre') {
        const next = this.resumen?.arrastre?.next_cuota || null;
        if (next && next.mes_nombre) {
          const nextNumero = Number(next?.numero_cuota || 0);
          if (nextNumero === numeroCuota) {
            return String(next.mes_nombre);
          }
        }

        const asignacionesArrastre = this.resumen?.arrastre?.asignacion_costos?.items || this.resumen?.asignaciones_arrastre || [];
        const asig = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === Number(numeroCuota));
        if (asig && asig.mes_nombre) {
          return String(asig.mes_nombre);
        }
      }

      const map = (this.resumen?.mensualidad_meses || []) as Array<any>;
      const hit = map.find(m => Number(m?.numero_cuota || 0) === Number(numeroCuota));
      if (hit && hit.mes_nombre) {
        return String(hit.mes_nombre);
      }
      const gestion = (this.resumen?.gestion || '').toString();
      const months = this.getGestionMonths(gestion);
      const idx = Number(numeroCuota) - 1;
      if (idx >= 0 && idx < months.length) {
        return this.monthName(months[idx]);
      }
      return null;
    } catch {
      return null;
    }
  }

  private getGestionMonths(gestion: string): number[] {
    try {
      const sem = parseInt((gestion || '').split('/')[0] || '0', 10);
      if (sem === 1) return [2, 3, 4, 5, 6];
      if (sem === 2) return [7, 8, 9, 10, 11];
      return [];
    } catch { return []; }
  }

  private monthName(n: number): string {
    const names = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
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
  private getOrderedCuotasRestantes(): Array<{ numero: number; restante: number; id_cuota_template: number | null; id_asignacion_costo: number | null; id_asignacion_mora: number | null; esMoraOrfana: boolean; }> {
    // Para arrastre, usar asignaciones_arrastre; para mensualidad, usar asignaciones
    let src: any[] = [];
    if (this.tipo === 'arrastre') {
      src = (this.resumen?.asignaciones_arrastre || []) as any[];
    } else {
      src = ((this.resumen?.asignacion_costos?.items || this.resumen?.asignaciones || []) as any[]);
      // El backend concatena primarias+arrastre en 'asignaciones'. Para mensualidad, excluir las de ARRASTRE.
      // NOTE: tipo_inscripcion en asignaciones es siempre 'NORMAL' (el query no join inscripciones),
      // por eso se usa exclusión por id_asignacion_costo comparando con asignaciones_arrastre.
      if (this.tipo === 'mensualidad') {
        const arrastreIds = new Set(
          ((this.resumen?.asignaciones_arrastre || []) as any[])
            .map((a: any) => Number(a?.id_asignacion_costo || 0))
            .filter((id: number) => id > 0)
        );
        src = src.filter((a: any) => {
          const id = Number(a?.id_asignacion_costo || 0);
          return id <= 0 || !arrastreIds.has(id);
        });
      }
    }
    const ord = (src || []).slice().sort((a: any, b: any) => Number(a?.numero_cuota || 0) - Number(b?.numero_cuota || 0));
    const out: Array<{ numero: number; restante: number; id_cuota_template: number | null; id_asignacion_costo: number | null; id_asignacion_mora: number | null; esMoraOrfana: boolean; }> = [];

    console.log('[MensualidadModal] getOrderedCuotasRestantes - tipo:', this.tipo, 'detalleFactura:', this.detalleFactura);
    console.log('[MensualidadModal] Total asignaciones en resumen:', src.length, 'pendientes (prop):', this.pendientes);

    // Obtener números de cuota ya agregados al detalle de factura para este tipo (mensualidad o arrastre)
    const cuotasYaAgregadas = new Set<number>();
    if (this.detalleFactura && Array.isArray(this.detalleFactura)) {
      for (const item of this.detalleFactura) {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        const numeroCuota = Number(item?.numero_cuota || 0);

        console.log('[MensualidadModal] Revisando item detalle - tipoPago:', tipoPago, 'numeroCuota:', numeroCuota);

        // Para mensualidad, filtrar items de MENSUALIDAD
        // Para arrastre, filtrar items de ARRASTRE
        if (this.tipo === 'mensualidad' && tipoPago === 'MENSUALIDAD' && numeroCuota > 0) {
          cuotasYaAgregadas.add(numeroCuota);
          console.log('[MensualidadModal] Cuota agregada al set:', numeroCuota);
        } else if (this.tipo === 'arrastre' && tipoPago === 'ARRASTRE' && numeroCuota > 0) {
          cuotasYaAgregadas.add(numeroCuota);
          console.log('[MensualidadModal] Cuota arrastre agregada al set:', numeroCuota);
        }
      }
    }

    console.log('[MensualidadModal] cuotasYaAgregadas:', Array.from(cuotasYaAgregadas));

    for (const a of ord) {
      const bruto = this.toNumberLoose(a?.monto);
      const desc = this.toNumberLoose(a?.descuento);
      const montoNeto = (a?.monto_neto !== undefined && a?.monto_neto !== null) ? this.toNumberLoose(a?.monto_neto) : Math.max(0, bruto - desc);
      const pagado = this.toNumberLoose(a?.monto_pagado);
      const numero = Number(a?.numero_cuota || 0);

      // Saltar cuotas completamente cobradas — estado_pago es la autoridad definitiva.
      // Evita que discrepancias en descuento/monto_neto muestren cuotas ya pagadas.
      const estadoPago = (a?.estado_pago || '').toString().toUpperCase();
      if (estadoPago === 'COBRADO') {
        console.log('[MensualidadModal] Cuota SKIP (estado COBRADO):', numero);
        continue;
      }

      // Saltar cuotas ya agregadas al detalle de factura
      if (cuotasYaAgregadas.has(numero)) {
        console.log('[MensualidadModal] Cuota FILTRADA (ya en detalle):', numero);
        continue;
      }

      let restante = Math.max(0, montoNeto - pagado);
      if (this.frontSaldos && Object.prototype.hasOwnProperty.call(this.frontSaldos, numero)) {
        const r = Number(this.frontSaldos[numero]);
        if (isFinite(r)) restante = Math.max(0, r);
      }
      if (restante > 0) {
        console.log('[MensualidadModal] Cuota AGREGADA a out:', numero, 'restante:', restante);
        out.push({
          numero,
          restante,
          id_cuota_template: (a?.id_cuota_template !== undefined && a?.id_cuota_template !== null) ? Number(a?.id_cuota_template) : null,
          id_asignacion_costo: (a?.id_asignacion_costo !== undefined && a?.id_asignacion_costo !== null) ? Number(a?.id_asignacion_costo) : null,
          id_asignacion_mora: null,
          esMoraOrfana: false,
        });
      } else {
        console.log('[MensualidadModal] Cuota NO agregada (restante <= 0):', numero, 'restante:', restante);
      }
    }

    console.log('[MensualidadModal] Array out ANTES de filtro override:', out.length, 'cuotas:', out.map(c => c.numero));
    // Si el padre indica una cuota inicial distinta (p.ej. porque ya se cobró el saldo en el front), filtrar
    // EXCEPTO cuando:
    // - Es mensualidad y hay arrastre en el detalle (el override viene del modal de arrastre)
    // - Es arrastre y hay mensualidad en el detalle (el override viene del modal de mensualidad)
    const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
      const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
      return tipoPago === 'ARRASTRE';
    });

    const hayMensualidadEnDetalle = (this.detalleFactura || []).some((item: any) => {
      const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
      return tipoPago === 'MENSUALIDAD';
    });

    const ignorarOverride = (this.tipo === 'mensualidad' && hayArrastreEnDetalle) || (this.tipo === 'arrastre' && hayMensualidadEnDetalle);

    // Prepend orphan moras: cuotas anteriores ya pagadas (mensualidad+arrastre COBRADO) pero mora aún PENDIENTE.
    // Bypasan el override filter porque son remanentes de meses anteriores que siempre deben mostrarse.
    const orphans = this.getOrphanMoras();

    if (this.startCuotaOverride && this.startCuotaOverride > 0 && !ignorarOverride) {
      const start = Number(this.startCuotaOverride);
      const filtered = out.filter(it => Number(it.numero) >= start);
      console.log('[MensualidadModal] Aplicando filtro override - startCuotaOverride:', start, 'resultado:', filtered.length, 'cuotas:', filtered.map(c => c.numero));
      return [...orphans, ...filtered];
    }
    console.log('[MensualidadModal] getOrderedCuotasRestantes RETORNA:', out.length + orphans.length, 'cuotas:', out.map(c => c.numero));
    return [...orphans, ...out];
  }

  // Moras huérfanas: cuotas donde mensualidad Y arrastre (si existe) están COBRADO pero la mora sigue PENDIENTE.
  // Se exponen como opciones seleccionables en ambos modales con su monto restante.
  private getOrphanMoras(): Array<{ numero: number; restante: number; id_cuota_template: null; id_asignacion_costo: null; id_asignacion_mora: number; esMoraOrfana: true; }> {
    try {
      const morasPendientes: any[] = this.morasPendientes || [];
      const asignaciones: any[] = this.resumen?.asignaciones || [];
      const asignacionesArrastre: any[] = this.resumen?.asignaciones_arrastre || [];
      const result: Array<{ numero: number; restante: number; id_cuota_template: null; id_asignacion_costo: null; id_asignacion_mora: number; esMoraOrfana: true; }> = [];

      for (const mora of morasPendientes) {
        const estado = (mora?.estado || '').toString().toUpperCase();
        if (!this.isMoraEstadoPendiente(estado)) continue;

        const idAsignMora = Number(mora?.id_asignacion_mora || 0);
        const idAsignCosto = Number(mora?.id_asignacion_costo || 0);
        const numCuota = Number(mora?.numero_cuota || 0);
        if (!idAsignMora || !numCuota || !idAsignCosto) continue;

        // La asignacion de mensualidad debe estar COBRADO
        const asignMens = asignaciones.find((a: any) => Number(a?.id_asignacion_costo || 0) === idAsignCosto);
        if (!asignMens) continue;
        if ((asignMens?.estado_pago || '').toString().toUpperCase() !== 'COBRADO') continue;

        // Si existe arrastre para esta cuota, también debe estar COBRADO
        const asignArrastre = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === numCuota);
        if (asignArrastre && (asignArrastre?.estado_pago || '').toString().toUpperCase() !== 'COBRADO') continue;

        // Omitir si la mora ya fue agregada al detalle de factura
        const yaEnDetalle = (this.detalleFactura || []).some((item: any) => {
          const tipo = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
          return tipo === 'MORA' && Number(item?.id_asignacion_mora || 0) === idAsignMora;
        });
        if (yaEnDetalle) continue;

        const moraRestante = this.recalcularMoraConFechaDeposito(mora);
        if (!(moraRestante > 0)) continue;

        result.push({ numero: numCuota, restante: moraRestante, id_cuota_template: null, id_asignacion_costo: null, id_asignacion_mora: idAsignMora, esMoraOrfana: true });
      }
      return result.sort((a, b) => a.numero - b.numero);
    } catch { return []; }
  }

  private esCuotaPagadaOEnDetalle(numeroCuota: number): {
    mensualidadPagada: boolean;
    arrastrePagado: boolean;
    mensualidadEnDetalle: boolean;
    arrastreEnDetalle: boolean;
  } {
    const asignaciones = this.resumen?.asignaciones || [];
    const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];

    console.log(`[esCuotaPagadaOEnDetalle] Verificando cuota ${numeroCuota}`);
    console.log(`[esCuotaPagadaOEnDetalle] detalleFactura:`, this.detalleFactura);

    // Verificar mensualidad (cobrada en BD o en detalle actual)
    const asignMensualidad = asignaciones.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuota);
    const estadoMens = asignMensualidad ? (asignMensualidad?.estado_pago || '').toString().toUpperCase() : '';
    const mensualidadCobrada = !!estadoMens && (estadoMens === 'COBRADO' || estadoMens === 'PAGADO');
    const mensualidadEnDetalle = (this.detalleFactura || []).some((item: any) => {
      const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
      const numCuota = Number(item?.numero_cuota || 0);
      return tipoPago === 'MENSUALIDAD' && numCuota === numeroCuota;
    });
    const mensualidadPagada = mensualidadCobrada || mensualidadEnDetalle;

    console.log(`[esCuotaPagadaOEnDetalle] Mensualidad cuota ${numeroCuota}: cobrada=${mensualidadCobrada}, enDetalle=${mensualidadEnDetalle}, pagada=${mensualidadPagada}`);

    // Verificar arrastre (cobrado en BD o en detalle actual)
    const asignArrastre = asignacionesArrastre.find((a: any) => Number(a?.numero_cuota || 0) === numeroCuota);
    let arrastrePagado = true; // Si no hay arrastre, considerarlo como "pagado"
    let arrastreEnDetalle = false;
    if (asignArrastre) {
      const estadoArr = (asignArrastre?.estado_pago || '').toString().toUpperCase();
      const arrastreCobrado = !!estadoArr && (estadoArr === 'COBRADO' || estadoArr === 'PAGADO');
      arrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
        const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
        const numCuota = Number(item?.numero_cuota || 0);
        return tipoPago === 'ARRASTRE' && numCuota === numeroCuota;
      });
      arrastrePagado = arrastreCobrado || arrastreEnDetalle;
      console.log(`[esCuotaPagadaOEnDetalle] Arrastre cuota ${numeroCuota}: cobrado=${arrastreCobrado}, enDetalle=${arrastreEnDetalle}, pagado=${arrastrePagado}`);
    } else {
      console.log(`[esCuotaPagadaOEnDetalle] Arrastre cuota ${numeroCuota}: NO EXISTE, considerado como pagado=true`);
    }

    return { mensualidadPagada, arrastrePagado, mensualidadEnDetalle, arrastreEnDetalle };
  }

  private verificarOrdenPago(): void {
    try {
      this.showOrdenPagoInfo = false;
      this.ordenPagoInfoMessage = '';
      this.moraPendienteDetectada = null;

      if (this.tipo === 'mensualidad') {
        const cuotasRestantes = this.getOrderedCuotasRestantes();

        // Si no hay cuotas pendientes, verificar si hay moras pendientes de inscripción NORMAL
        if (cuotasRestantes.length === 0) {
          console.log('[MensualidadModal] No hay mensualidades pendientes, verificando moras de inscripción NORMAL');

          const morasPendientes = this.morasPendientes || [];
          const asignaciones = this.resumen?.asignaciones || [];

          console.log('[MensualidadModal] morasPendientes:', morasPendientes);
          console.log('[MensualidadModal] asignaciones:', asignaciones);

          // Buscar solo moras de inscripción NORMAL (id_asignacion_costo apunta a mensualidad normal y sin vinculación)
          const moraPendiente = morasPendientes.find((m: any) => {
            const estado = (m?.estado || '').toString().toUpperCase();
            const idAsignCostoMora = Number(m?.id_asignacion_costo || 0);
            const idAsignVinculada = Number(m?.id_asignacion_vinculada || 0);

            if (!this.isMoraEstadoPendiente(estado)) {
              return false;
            }

            // Excluir moras de arrastre (tienen id_asignacion_vinculada > 0)
            if (idAsignVinculada > 0) {
              console.log(`[MensualidadModal] Mora id_asignacion_costo=${idAsignCostoMora} DESCARTADA - es de arrastre (id_asignacion_vinculada=${idAsignVinculada})`);
              return false;
            }

            // Verificar que la mora sea de inscripción NORMAL
            // La mora de inscripción NORMAL tiene id_asignacion_costo apuntando a una mensualidad normal
            const esMoraNormal = asignaciones.some((a: any) => {
              return Number(a?.id_asignacion_costo || 0) === idAsignCostoMora;
            });

            console.log(`[MensualidadModal] Mora id_asignacion_costo=${idAsignCostoMora}, esMoraNormal=${esMoraNormal}`);
            return esMoraNormal;
          });

          console.log('[MensualidadModal] moraPendiente de inscripción NORMAL encontrada:', moraPendiente);

          if (moraPendiente) {
            const numeroCuotaMora = Number(moraPendiente?.numero_cuota || 0);
            const mesNombre = this.getMesNombreByCuota(numeroCuotaMora);
            this.showOrdenPagoInfo = true;
            this.moraPendienteDetectada = moraPendiente;
            this.ordenPagoInfoMessage = `⚠️ No hay mensualidades pendientes, pero tiene mora pendiente de la Cuota ${numeroCuotaMora}${mesNombre ? ' (' + mesNombre + ')' : ''}.`;
          }
          return;
        }

        const primeraCuotaMensualidadDisponible = cuotasRestantes[0].numero;
        const cantidadSeleccionada = Math.max(1, Number(this.form?.get('cantidad')?.value || 1));
        const ultimaCuotaSeleccionada = primeraCuotaMensualidadDisponible + cantidadSeleccionada - 1;

        console.log('[MensualidadModal] verificarOrdenPago - MENSUALIDAD - Verificando mora');
        console.log('[MensualidadModal] ultimaCuotaSeleccionada:', ultimaCuotaSeleccionada);
        console.log('[MensualidadModal] detalleFactura:', this.detalleFactura);

        const morasPendientes = this.morasPendientes || [];
        console.log('[MensualidadModal] morasPendientes:', morasPendientes);

        // Verificar si hay arrastre en el detalle
        const hayArrastreEnDetalleParaMora = (this.detalleFactura || []).some((item: any) => {
          const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
          return tipoPago === 'ARRASTRE';
        });

        console.log('[MensualidadModal] hayArrastreEnDetalleParaMora:', hayArrastreEnDetalleParaMora);

        let moraPendienteAnterior = null;

        if (hayArrastreEnDetalleParaMora) {
          console.log('[MensualidadModal] HAY arrastre en detalle, NO verificar mora (se permite pagar mensualidad)');
          // Con la nueva lógica: si hay arrastre en detalle, NO bloquear por mora
          // La mora se agregará automáticamente cuando se agregue la mensualidad
          moraPendienteAnterior = null;
        } else {
          console.log('[MensualidadModal] NO hay arrastre en detalle, buscando mora de cuotas ya pagadas...');

          // Obtener las asignaciones de mensualidad y arrastre
          // Filtrar solo NORMAL para no confundir con asignaciones arrastre del mismo número de cuota
          const asignaciones = (this.resumen?.asignaciones || []).filter((a: any) => {
            const ti = (a?.tipo_inscripcion || 'NORMAL').toString().toUpperCase();
            return ti !== 'ARRASTRE';
          });
          const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];

          // Buscar moras pendientes cuyas mensualidades y arrastres ya fueron pagados
          const morasPendientesValidas = morasPendientes.filter((m: any) => {
            const estado = (m?.estado || '').toString().toUpperCase();
            const numeroCuotaMora = Number(m?.numero_cuota || 0);
            const idAsignCostoMora = Number(m?.id_asignacion_costo || 0);

            if (!this.isMoraEstadoPendiente(estado) || numeroCuotaMora >= ultimaCuotaSeleccionada) {
              return false;
            }

            // Verificar si la mensualidad de esta cuota ya fue pagada
            const asignMensualidad = asignaciones.find((a: any) => {
              return Number(a?.numero_cuota || 0) === numeroCuotaMora;
            });

            if (!asignMensualidad) {
              return false;
            }

            const idAsignMensualidad = Number(asignMensualidad?.id_asignacion_costo || 0);

            // IMPORTANTE: Verificar que la mora sea de inscripción NORMAL
            // La mora debe tener id_asignacion_costo apuntando a la mensualidad normal
            if (idAsignCostoMora !== idAsignMensualidad) {
              console.log(`[MensualidadModal] Mora cuota ${numeroCuotaMora}: DESCARTADA - no es de inscripción normal (id_asignacion_costo: ${idAsignCostoMora} != ${idAsignMensualidad})`);
              return false;
            }

            // Usar función helper para verificar si cuota está pagada o en detalle
            const { mensualidadPagada, arrastrePagado } = this.esCuotaPagadaOEnDetalle(numeroCuotaMora);

            const cumple = mensualidadPagada && arrastrePagado;
            console.log(`[MensualidadModal] Mora cuota ${numeroCuotaMora}: mensualidadPagada=${mensualidadPagada}, arrastrePagado=${arrastrePagado}, cumple=${cumple}`);
            return cumple;
          });

          // Ordenar por numero_cuota descendente y tomar la primera (la más reciente)
          if (morasPendientesValidas.length > 0) {
            morasPendientesValidas.sort((a: any, b: any) => {
              return Number(b?.numero_cuota || 0) - Number(a?.numero_cuota || 0);
            });
            moraPendienteAnterior = morasPendientesValidas[0];
          }

          console.log('[MensualidadModal] moraPendienteAnterior (de cuotas pagadas):', moraPendienteAnterior);
        }

        const hayArrastrePendiente = this.tieneArrastrePendiente();
        console.log('[MensualidadModal] tieneArrastrePendiente():', hayArrastrePendiente);

        // Si hay arrastre pendiente, la mensualidad se paga independientemente — no bloquear por mora
        if (moraPendienteAnterior && hayArrastrePendiente) {
          console.log('[MensualidadModal] Suprimiendo mora porque hay arrastre pendiente');
          moraPendienteAnterior = null;
        }

        // Verificar si la mora ya está pagada o en el detalle de factura
        if (moraPendienteAnterior) {
          const idMoraDetectada = Number(moraPendienteAnterior?.id_asignacion_mora || 0);

          // Verificar si está en el detalle actual
          const moraYaEnDetalle = (this.detalleFactura || []).some((item: any) => {
            const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
            const idMora = Number(item?.id_asignacion_mora || 0);
            return tipoPago === 'MORA' && idMora === idMoraDetectada;
          });

          // Verificar el estado real desde el resumen actualizado
          const morasActualizadas = this.resumen?.moras_pendientes || [];
          const moraActualizada = morasActualizadas.find((m: any) => {
            return Number(m?.id_asignacion_mora || 0) === idMoraDetectada;
          });
          const estadoReal = moraActualizada ? (moraActualizada?.estado || '').toString().toUpperCase() : 'DESCONOCIDO';
          const moraYaPagada = estadoReal === 'PAGADO' || estadoReal === 'COBRADO';

          console.log('[MensualidadModal] Mora ya en detalle:', moraYaEnDetalle, 'id_mora:', idMoraDetectada, 'estadoReal:', estadoReal, 'moraYaPagada:', moraYaPagada);

          if (moraYaEnDetalle || moraYaPagada) {
            moraPendienteAnterior = null;
          }
        }

        // Mostrar advertencia de mora si hay mora pendiente encontrada
        if (moraPendienteAnterior) {
          const numeroCuotaMora = Number(moraPendienteAnterior?.numero_cuota || 0);

          console.log('[MensualidadModal] Mora detectada - numeroCuotaMora:', numeroCuotaMora, 'ultimaCuotaSeleccionada:', ultimaCuotaSeleccionada);

          // IMPORTANTE: Solo mostrar advertencia si la mora es de una cuota ANTERIOR
          // Si la mora es de la MISMA cuota que se está pagando, NO bloquear
          if (numeroCuotaMora < ultimaCuotaSeleccionada) {
            const mesNombreCuota = this.getMesNombreByCuota(numeroCuotaMora);

            // Obtener el mes de la mora desde fecha_inicio_mora
            const fechaInicioMora = moraPendienteAnterior?.fecha_inicio_mora || '';
            let mesNombreMora = '';
            if (fechaInicioMora) {
              const fecha = new Date(fechaInicioMora);
              const mesNum = fecha.getMonth() + 1;
              const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
              mesNombreMora = meses[mesNum - 1] || '';
            }

            console.log('[MensualidadModal] MOSTRANDO ADVERTENCIA DE MORA (cuota anterior)');
            this.showOrdenPagoInfo = true;
            this.moraPendienteDetectada = moraPendienteAnterior;
            const mensajeCuota = mesNombreCuota ? ` (${mesNombreCuota})` : '';
            const mensajeMora = mesNombreMora ? ` - Mora de ${mesNombreMora}` : '';
            this.ordenPagoInfoMessage = `⚠️ Debe pagar primero la mora pendiente de la Cuota ${numeroCuotaMora}${mensajeCuota}${mensajeMora} antes de poder pagar mensualidades posteriores.`;
            return;
          } else {
            console.log('[MensualidadModal] NO se muestra advertencia porque la mora es de la MISMA cuota que se está pagando');
          }
        } else {
          console.log('[MensualidadModal] NO se muestra advertencia porque no hay mora pendiente');
        }
      } else if (this.tipo === 'arrastre') {
        const next = this.resumen?.arrastre?.next_cuota || null;

        // Si no hay cuotas de arrastre pendientes, verificar si hay moras pendientes
        if (!next) {
          const morasPendientes = this.morasPendientes || [];
          const moraPendiente = morasPendientes.find((m: any) => {
            const estado = (m?.estado || '').toString().toUpperCase();
            return this.isMoraEstadoPendiente(estado);
          });

          if (moraPendiente) {
            const numeroCuotaMora = Number(moraPendiente?.numero_cuota || 0);
            const mesNombre = this.getMesNombreByCuota(numeroCuotaMora);
            this.showOrdenPagoInfo = true;
            this.moraPendienteDetectada = moraPendiente;
            this.ordenPagoInfoMessage = `⚠️ No hay arrastre pendiente, pero tiene mora pendiente de la Cuota ${numeroCuotaMora}${mesNombre ? ' (' + mesNombre + ')' : ''}.`;
          }
          return;
        }

        const numeroCuotaArrastreActual = Number(next?.numero_cuota || 0);
        // Excluir moras huérfanas: son cuotas cuya mensualidad YA está cobrada, no bloquean el arrastre.
        const cuotasRestantes = this.getOrderedCuotasRestantes().filter(c => !c.esMoraOrfana);
        const mensualidadPendiente = cuotasRestantes.find(c => c.numero < numeroCuotaArrastreActual);

        if (mensualidadPendiente) {
          const mesNombre = this.getMesNombreByCuota(mensualidadPendiente.numero);
          this.showOrdenPagoInfo = true;
          this.ordenPagoInfoMessage = `⚠️ Debe pagar primero la mensualidad de la Cuota ${mensualidadPendiente.numero}${mesNombre ? ' (' + mesNombre + ')' : ''} antes de poder pagar arrastre.`;
          return;
        }

        // La mora de cuotas anteriores NO bloquea el arrastre de forma independiente.
        // La mora se agrega automáticamente al batch cuando se completa el pago del mes
        // (mensualidad + arrastre de la misma cuota juntos).
      }
    } catch (error) {
      console.error('[MensualidadModal] Error en verificarOrdenPago:', error);
    }
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
          dentroFecha = isOnOrBeforeDeadlineLocal(this.descuentoSemestreFechaLimite);
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

    // Obtener el monto de mora para restarlo del monto parcial
    const moraMonto = this.getMoraPendienteMonto();
    // El monto real de mensualidad es el monto parcial menos la mora
    const montoMensualidad = Math.max(0, montoPagoParcial - moraMonto);

    console.log('[MensualidadModal] Montos para cálculo de descuento:', {
      montoPagoParcial,
      moraMonto,
      montoMensualidad
    });

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

      // Fórmula: descuento_pago * (monto_mensualidad / total_debe_pagar)
      // Usar montoMensualidad (sin mora) para calcular la proporción
      const proporcion = montoMensualidad / totalDebePagar;
      const descuentoProrrateado = descuentoPago * proporcion;

      console.log('[MensualidadModal] Cálculo de descuento prorrateado:', {
        montoMensualidad,
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

  private getSelectedForma(): any {
    try {
      const metodo = (this.form.get('metodo_pago')?.value || '').toString().trim();
      if (!metodo) return null;
      const list: any[] = Array.isArray(this.formasCobro) ? this.formasCobro : [];
      const hit = list.find((x: any) => `${x?.id_forma_cobro ?? ''}` === metodo || `${x?.codigo_sin ?? ''}` === metodo);
      return hit || null;
    } catch { return null; }
  }

  private getSelectedCodigoSin(): number | null {
    try {
      const f = this.getSelectedForma();
      const raw = f?.codigo_sin ?? f?.codigo ?? null;
      const n = Number(raw);
      return isFinite(n) && n > 0 ? n : null;
    } catch { return null; }
  }

  get isQR(): boolean {
    try {
      const f = this.getSelectedForma();
      const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
      if (!nameRaw) return false;
      const nombre = nameRaw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      return nombre.includes('QR');
    } catch { return false; }
  }

  get isTransferencia(): boolean {
    try {
      const f = this.getSelectedForma();
      const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
      if (!nameRaw) return false;
      const nombre = nameRaw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      return nombre.includes('TRANSFER');
    } catch { return false; }
  }

  get isTransferenciaBancaria(): boolean {
    return this.isTransferencia && !this.isQR;
  }

  get isOtro(): boolean {
    try {
      const f = this.getSelectedForma();
      const id = (f?.id_forma_cobro ?? '').toString().trim().toUpperCase();
      if (id === 'OTRO') return true;
      const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
      if (!nameRaw) return false;
      const nombre = nameRaw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      return nombre.includes('OTRO');
    } catch { return false; }
  }

  labelForma(f: any): string {
    try {
      return (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? f?.id_forma_cobro ?? '').toString().trim();
    } catch { return ''; }
  }

  /**
   * Calcula los campos adicionales de traspaso (tipo, concepto, gestion_destino)
   * para incluir en cada item de pago que se envía al backend.
   */
  private buildTraspasoComputedFields(): { traspaso_tipo: string; traspaso_concepto: string; traspaso_gestion_destino: string } {
    const carreraOrigen = (this.form.get('traspaso_carrera_origen')?.value || '').toString().trim().toUpperCase();
    const carreraActual = (this.resumen?.estudiante?.carrera || '').toString().trim().toUpperCase();
    const tipo = (carreraOrigen && carreraActual && carreraOrigen !== carreraActual) ? 'M' : 'T';
    const documento = (this.form.get('traspaso_documento')?.value || '').toString().trim();
    const fechaOrigen = (this.form.get('traspaso_fecha_origen')?.value || '').toString().trim();
    const concepto = tipo === 'M'
      ? `Traspaso de pago entre carreras segun documento: "${documento}" en "${fechaOrigen}"`
      : `Traspaso de mensualidad segun documento: "${documento}" en "${fechaOrigen}"`;
    return {
      traspaso_tipo: tipo,
      traspaso_concepto: concepto,
      traspaso_gestion_destino: (this.resumen?.gestion || '').toString(),
    };
  }

  get isTraspaso(): boolean {
    try {
      const f = this.getSelectedForma();
      const nameRaw = (f?.descripcion_sin ?? f?.nombre ?? f?.name ?? f?.descripcion ?? f?.label ?? '').toString().trim().toUpperCase();
      if (!nameRaw) return false;
      const nombre = nameRaw.normalize('NFD').replace(/\p{Diacritic}/gu, '');
      return nombre.includes('TRASPASO');
    } catch { return false; }
  }

  get gestionesActivasFiltradas(): any[] {
    return this.gestionesActivas.filter(g => g.activo === true || g.activo === 1);
  }

  get carrerasActivas(): any[] {
    return this.carreras.filter(c => c.estado === true || c.estado === 1);
  }

  get showBancarioBlock(): boolean {
    return this.isCheque || this.isDeposito || this.isTransferenciaBancaria;
  }

  private updateTarjetaValidators(): void {
    try {
      const setReq = (name: string, req: boolean, extra: any[] = []) => {
        const c = this.form.get(name);
        if (!c) return;
        if (req) {
          c.setValidators([Validators.required, ...extra]);
          c.enable({ emitEvent: false });
        } else {
          c.clearValidators();
          // IMPORTANTE: no deshabilitar inputs bancarios/tarjeta. Si quedan con "disabled",
          // el navegador no asigna foco y el usuario no puede escribir (activeElement queda en BODY).
          c.enable({ emitEvent: false });
        }
        c.updateValueAndValidity({ emitEvent: false });
      };

      setReq('id_cuentas_bancarias', false);
      setReq('fecha_deposito', false);
      setReq('nro_deposito', false);
      setReq('banco_origen', false);
      setReq('tarjeta_first4', false);
      setReq('tarjeta_last4', false);
      setReq('traspaso_gestion', false);
      setReq('traspaso_nro_cuota', false);
      setReq('traspaso_cod_est', false);
      setReq('traspaso_fecha_origen', false);
      setReq('traspaso_carrera_origen', false);
      setReq('traspaso_documento', false);

      if (this.isTraspaso) {
        setReq('traspaso_gestion', true);
        setReq('traspaso_nro_cuota', true);
        setReq('traspaso_cod_est', true);
        setReq('traspaso_fecha_origen', true);
        setReq('traspaso_carrera_origen', true);
        setReq('traspaso_documento', true);
        return;
      }
      if (this.isTarjeta) {
        setReq('id_cuentas_bancarias', true);
        setReq('fecha_deposito', true);
        setReq('nro_deposito', true);
        setReq('banco_origen', true);
        setReq('tarjeta_first4', true, [Validators.pattern(/^\d{4}$/)]);
        setReq('tarjeta_last4', true, [Validators.pattern(/^\d{4}$/)]);
        return;
      }
      if (this.isTransferenciaBancaria) {
        setReq('id_cuentas_bancarias', true);
        setReq('fecha_deposito', true);
        setReq('nro_deposito', true);
        setReq('banco_origen', true);
        return;
      }
      if (this.isCheque || this.isDeposito) {
        setReq('id_cuentas_bancarias', true);
        setReq('fecha_deposito', true);
        setReq('nro_deposito', true);
        return;
      }
      if (this.isQR) {
        // QR no requiere cuenta destino ni datos bancarios (se gestiona en el flujo QR)
        setReq('id_cuentas_bancarias', false);
        return;
      }
    } catch {
      // No-op
    }
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

  addAndClose(): void {
    console.log('[MensualidadModal] ===== INICIO addAndClose() =====');

    // Validar que haya opciones disponibles para mensualidad y arrastre
    if (this.tipo === 'mensualidad' || this.tipo === 'arrastre') {
      const opciones = this.getCantidadOptions();
      console.log('[MensualidadModal] addAndClose - Validando opciones disponibles. opciones.length:', opciones.length);
      if (opciones.length === 0) {
        this.modalAlertMessage = 'No hay cuotas disponibles para agregar. Todas las cuotas pendientes ya han sido agregadas al detalle.';
        this.modalAlertType = 'warning';
        console.log('[MensualidadModal] addAndClose - BLOQUEADO: No hay opciones disponibles');
        return;
      }
    }

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
      const esParcial = !!this.form.get('pago_parcial')?.value;
      const cant = Math.max(0, Number(this.form.get('cantidad')?.value || 0));

      console.log('[MensualidadModal] addAndClose ARRASTRE - esParcial:', esParcial, 'cantidad:', cant);

      if (cant === 0 || esParcial) {
        // Pago de 1 arrastre (parcial o completo de una sola cuota)
        const next = this.resumen?.arrastre?.next_cuota || null;

        // Para arrastre, el PU/monto debe reflejar el NETO (monto - descuento) del item de arrastre
        let montoArrastre = next ? this.toNumberLoose((next as any)?.monto_neto) : 0;
        if (montoArrastre <= 0) montoArrastre = Number(this.pu || 0);

        const numeroCuotaArrastre = next ? Number(next?.numero_cuota || 0) : 0;
        const mesNombre = numeroCuotaArrastre ? this.getMesNombreByCuota(numeroCuotaArrastre) : null;
        const detalleArrastre = mesNombre
          ? `Arrastre - Cuota ${numeroCuotaArrastre} (${mesNombre})`
          : `Arrastre - Cuota ${numeroCuotaArrastre || ''}`;

        let montoParaArrastre = montoArrastre;
        let montoParaMora = 0;

        if (esParcial) {
          const montoParcialTotal = Number(this.form.get('monto_parcial')?.value || 0);
          // Si el monto parcial cubre el arrastre completo, el resto va a la mora
          if (montoParcialTotal >= montoArrastre) {
            montoParaArrastre = montoArrastre;
            montoParaMora = montoParcialTotal - montoArrastre;
          } else {
            // El pago solo cubre parte del arrastre, no hay pago de mora
            montoParaArrastre = montoParcialTotal;
            montoParaMora = 0;
          }
        }

        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto: montoParaArrastre,
          fecha_cobro: hoy,
          observaciones: this.composeObservaciones(),
          pu_mensualidad: montoParaArrastre,
          detalle: detalleArrastre,
          cod_tipo_cobro: 'ARRASTRE',
          tipo_pago: 'ARRASTRE',
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
          traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
          traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
          traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
          traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
          traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
          traspaso_documento: this.form.get('traspaso_documento')?.value || null,
          ...this.buildTraspasoComputedFields(),
          // opcionales
          descuento: this.form.get('descuento')?.value || null,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
        });

        // Agregar mora de arrastre SOLO si la mensualidad de esta cuota está pagada O en detalle
        const { mensualidadPagada } = this.esCuotaPagadaOEnDetalle(numeroCuotaArrastre);

        console.log('[MensualidadModal] addAndClose ARRASTRE (1 cuota) - mensualidadPagada:', mensualidadPagada, 'numeroCuota:', numeroCuotaArrastre);

        if (mensualidadPagada) {
          try {
            const moras = this.getMorasPendientesSinVerificarPar(next ? (next?.id_asignacion_costo ?? null) : null, 'arrastre', numeroCuotaArrastre);
            console.log('[MensualidadModal] addAndClose ARRASTRE (1 cuota) - moras encontradas:', moras);

            if (moras.length) {
              if (esParcial && montoParaMora > 0) {
                let pendienteDistribuir = montoParaMora;
                for (const mora of moras) {
                  if (!(pendienteDistribuir > 0)) break;
                  const saldoMora = this.recalcularMoraConFechaDeposito(mora);
                  if (!(saldoMora > 0)) continue;

                  const montoMoraParcial = Math.min(pendienteDistribuir, saldoMora);
                  const moraItem = this.buildPagoMoraItem(mora, hoy, compSel, tipo_documento, medio_doc);
                  moraItem.nro_cobro = (this.baseNro || 1) + pagos.length;
                  moraItem.monto = montoMoraParcial;
                  moraItem.pu_mensualidad = montoMoraParcial;
                  pagos.push(moraItem);
                  pendienteDistribuir -= montoMoraParcial;
                }
              } else if (!esParcial) {
                for (const mora of moras) {
                  const saldoMora = this.recalcularMoraConFechaDeposito(mora);
                  if (!(saldoMora > 0)) continue;
                  const moraItem = this.buildPagoMoraItem(mora, hoy, compSel, tipo_documento, medio_doc);
                  moraItem.nro_cobro = (this.baseNro || 1) + pagos.length;
                  pagos.push(moraItem);
                }
              }
            } else {
              console.log('[MensualidadModal] addAndClose ARRASTRE (1 cuota) - NO se encontró mora pendiente');
            }
          } catch (err) {
            console.error('[MensualidadModal] addAndClose ARRASTRE (1 cuota) - Error al agregar mora:', err);
          }
        } else {
          console.log('[MensualidadModal] addAndClose ARRASTRE (1 cuota) - NO agregar mora porque mensualidad NO está pagada');
        }
      } else {
        // Pago de múltiples arrastres (cant > 0)
        console.log('[MensualidadModal] addAndClose ARRASTRE - Pago de múltiples arrastres, cantidad:', cant);
        const list = this.getOrderedCuotasRestantes().slice(0, cant);
        let nro = this.baseNro || 1;

        for (const cuota of list) {
          // Mora huérfana: emitir pago de MORA directamente en vez de pago de ARRASTRE
          if (cuota.esMoraOrfana && cuota.id_asignacion_mora) {
            const moraObj = (this.morasPendientes || []).find((m: any) =>
              Number(m?.id_asignacion_mora || 0) === cuota.id_asignacion_mora
            );
            if (moraObj) {
              const moraItem = this.buildPagoMoraItem(moraObj, hoy, compSel, tipo_documento, medio_doc);
              moraItem.nro_cobro = nro++;
              pagos.push(moraItem);
            }
            continue;
          }

          const numero_cuota = Number(cuota?.numero || 0);
          const id_asignacion_costo = cuota?.id_asignacion_costo ?? null;
          const id_cuota_template = cuota?.id_cuota_template ?? null;
          const montoArrastre = cuota?.restante || Number(this.pu || 0);

          const mesNombre = numero_cuota ? this.getMesNombreByCuota(numero_cuota) : null;
          const detalleArrastre = mesNombre
            ? `Nivelación - Cuota ${numero_cuota} (${mesNombre})`
            : `Nivelación - Cuota ${numero_cuota || ''}`;

          pagos.push({
            id_forma_cobro: this.form.get('metodo_pago')?.value || null,
            nro_cobro: nro++,
            monto: montoArrastre,
            fecha_cobro: hoy,
            observaciones: this.composeObservaciones(),
            pu_mensualidad: montoArrastre,
            detalle: detalleArrastre,
            cod_tipo_cobro: 'ARRASTRE',
            tipo_pago: 'ARRASTRE',
            numero_cuota,
            id_cuota: id_cuota_template,
            id_asignacion_costo,
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
            traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
            traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
            traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
            traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
            traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
            traspaso_documento: this.form.get('traspaso_documento')?.value || null,
            ...this.buildTraspasoComputedFields(),
            // opcionales
            descuento: this.form.get('descuento')?.value || null,
            nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
            nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
          });

          console.log('[MensualidadModal] addAndClose ARRASTRE (múltiples) - Agregado arrastre cuota:', numero_cuota);

          // Agregar mora SOLO si la mensualidad de esta cuota está pagada
          const { mensualidadPagada } = this.esCuotaPagadaOEnDetalle(numero_cuota);
          console.log('[MensualidadModal] addAndClose ARRASTRE (múltiples) - cuota:', numero_cuota, 'mensualidadPagada:', mensualidadPagada);

          if (mensualidadPagada) {
            try {
              const moras = this.getMorasPendientesSinVerificarPar(id_asignacion_costo, 'arrastre', numero_cuota);
              console.log('[MensualidadModal] addAndClose ARRASTRE (múltiples) - moras encontradas para cuota', numero_cuota, ':', moras);

              for (const mora of moras) {
                const saldoMora = this.recalcularMoraConFechaDeposito(mora);
                if (!(saldoMora > 0)) continue;
                const moraItem = this.buildPagoMoraItem(mora, hoy, compSel, tipo_documento, medio_doc);
                moraItem.nro_cobro = nro++;
                pagos.push(moraItem);
                console.log('[MensualidadModal] addAndClose ARRASTRE (múltiples) - mora agregada para cuota:', numero_cuota);
              }
            } catch (err) {
              console.error('[MensualidadModal] addAndClose ARRASTRE (múltiples) - Error al agregar mora cuota', numero_cuota, ':', err);
            }
          }
        }
      }
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
        const numeroCuotaParcial = Number(numero_cuota || 0);
        const mesNombreParcial = numeroCuotaParcial ? this.getMesNombreByCuota(numeroCuotaParcial) : null;
        const detalleParcial = mesNombreParcial
          ? `Mensualidad - Cuota ${numeroCuotaParcial} (${mesNombreParcial}) (Parcial)`
          : `Mensualidad - Cuota ${numeroCuotaParcial || ''} (Parcial)`;

        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto: monto,
          fecha_cobro: hoy,
          observaciones: this.composeObservaciones(),
          pu_mensualidad: Number(this.puDisplay || this.pu || 0),
          detalle: detalleParcial,
          cod_tipo_cobro: 'MENSUALIDAD',
          tipo_pago: 'MENSUALIDAD',
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
          traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
          traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
          traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
          traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
          traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
          traspaso_documento: this.form.get('traspaso_documento')?.value || null,
          ...this.buildTraspasoComputedFields(),
          // Enviar el descuento prorrateado calculado
          descuento: descuentoProrrateado || null,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
          // targeting de cuota
          numero_cuota,
          id_cuota: id_cuota_template,
          id_asignacion_costo
        });

        // Si este parcial completa la cuota, agregar mora pendiente de esa cuota
        try {
          if (this.shouldCobrarMoraForPago(monto, numero_cuota, id_asignacion_costo)) {
            const moras = this.getMorasPendientesByAsign(id_asignacion_costo, numero_cuota ?? undefined);
            for (const mora of moras) {
              const saldoMora = this.recalcularMoraConFechaDeposito(mora);
              if (!(saldoMora > 0)) continue;
              const moraItem = this.buildPagoMoraItem(mora, hoy, compSel, tipo_documento, medio_doc);
              moraItem.nro_cobro = (this.baseNro || 1) + pagos.length;
              pagos.push(moraItem);
            }
          }
        } catch { }
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
            // Mora huérfana: emitir pago de MORA directamente en vez de pago de MENSUALIDAD
            if ((list[i] as any).esMoraOrfana && (list[i] as any).id_asignacion_mora) {
              const moraObj = (this.morasPendientes || []).find((m: any) =>
                Number(m?.id_asignacion_mora || 0) === (list[i] as any).id_asignacion_mora
              );
              if (moraObj) {
                const moraItem = this.buildPagoMoraItem(moraObj, hoy, compSel, tipo_documento, medio_doc);
                moraItem.nro_cobro = nro++;
                pagos.push(moraItem);
              }
              continue;
            }

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

            // Construir detalle de mensualidad
            const mesNombre = numero_cuota ? this.getMesNombreByCuota(numero_cuota) : null;
            const esParcial = esPagoParcialAuto || (montoPagadoPrevio > 0);
            const detalleMensualidad = mesNombre
              ? `Mensualidad - Cuota ${numero_cuota} (${mesNombre})${esParcial ? ' (Parcial)' : ''}`
              : `Mensualidad - Cuota ${numero_cuota || ''}${esParcial ? ' (Parcial)' : ''}`;

            pagos.push({
              id_forma_cobro: this.form.get('metodo_pago')?.value || null,
              nro_cobro: nro++,
              monto: m,
              fecha_cobro: hoy,
              observaciones: this.composeObservaciones(),
              pu_mensualidad: pu_unit,
              detalle: detalleMensualidad,
              cod_tipo_cobro: 'MENSUALIDAD',
              tipo_pago: 'MENSUALIDAD',
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
              traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
              traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
              traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
              traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
              traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
              traspaso_documento: this.form.get('traspaso_documento')?.value || null,
              ...this.buildTraspasoComputedFields(),
              descuento: descUnit || null,
              nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
              nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
            });

            // Agregar mora por cada cuota si existe mora pendiente
            // Si hay arrastre en el detalle, agregar la mora de inscripción NORMAL asociada (solo una vez)
            // Si NO hay arrastre (ni pendiente ni en detalle), agregar mora de cada cuota de mensualidad
            const hayArrastreEnDetalle = (this.detalleFactura || []).some((item: any) => {
              const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
              return tipoPago === 'ARRASTRE';
            });

            if (hayArrastreEnDetalle && i === 0) {
              // Solo agregar la mora de inscripción NORMAL una vez (en la primera iteración)
              try {
                const itemArrastre = (this.detalleFactura || []).find((item: any) => {
                  const tipoPago = (item?.tipo_pago || item?.cod_tipo_cobro || '').toString().toUpperCase();
                  return tipoPago === 'ARRASTRE';
                });

                if (itemArrastre) {
                  const numeroCuotaArrastre = Number(itemArrastre?.numero_cuota || 0);
                  const asignacionesArrastre = this.resumen?.asignaciones_arrastre || [];
                  const asignArrastre = asignacionesArrastre.find((a: any) => {
                    return Number(a?.numero_cuota || 0) === numeroCuotaArrastre;
                  });

                  if (asignArrastre) {
                    const idAsignArrastre = asignArrastre?.id_asignacion_costo ?? null;
                    const morasNormal = this.getMorasPendientesSinVerificarPar(idAsignArrastre, 'arrastre', numeroCuotaArrastre);
                    for (const moraNormal of morasNormal) {
                      const saldoMora = this.recalcularMoraConFechaDeposito(moraNormal);
                      if (!(saldoMora > 0)) continue;
                      const moraItem = this.buildPagoMoraItem(moraNormal, hoy, compSel, tipo_documento, medio_doc);
                      moraItem.nro_cobro = nro++;
                      pagos.push(moraItem);
                    }
                  }
                }
              } catch { }
            } else if (!hayArrastreEnDetalle) {
              // No hay arrastre en detalle: agregar mora de cada cuota de mensualidad
              try {
                const moras = this.getMorasPendientesByAsign(id_asignacion_costo, numero_cuota ?? undefined);
                for (const mora of moras) {
                  const saldoMora = this.recalcularMoraConFechaDeposito(mora);
                  if (!(saldoMora > 0)) continue;
                  const moraItem = this.buildPagoMoraItem(mora, hoy, compSel, tipo_documento, medio_doc);
                  moraItem.nro_cobro = nro++;
                  pagos.push(moraItem);
                }
              } catch { }
            }
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
              traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
              traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
              traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
              traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
              traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
              traspaso_documento: this.form.get('traspaso_documento')?.value || null,
              ...this.buildTraspasoComputedFields(),
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
              traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
              traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
              traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
              traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
              traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
              traspaso_documento: this.form.get('traspaso_documento')?.value || null,
              ...this.buildTraspasoComputedFields(),
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
        traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
        traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
        traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
        traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
        traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
        traspaso_documento: this.form.get('traspaso_documento')?.value || null,
        ...this.buildTraspasoComputedFields(),
        // opcionales
        descuento: this.form.get('descuento')?.value || null,
        nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
        nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
      });
    } else if (this.tipo === 'mora') {
      // Mora: generar pagos por cada mora seleccionada (similar a mensualidades)
      const cant = Math.max(1, Number(this.form.get('cantidad')?.value || 1));
      const isParcial = this.form.get('pago_parcial')?.value;
      const montoParcial = isParcial ? Number(this.form.get('monto_parcial')?.value || 0) : 0;

      if (isParcial && montoParcial > 0) {
        // Pago parcial: un solo registro con el monto parcial
        pagos.push({
          id_forma_cobro: this.form.get('metodo_pago')?.value || null,
          nro_cobro: this.baseNro || 1,
          monto: montoParcial,
          fecha_cobro: hoy,
          observaciones: this.composeObservaciones(),
          pu_mensualidad: Number(this.pu || 0),
          detalle: 'Pago Parcial de Mora',
          tipo_pago: 'MORA',
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
          traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
          traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
          traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
          traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
          traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
          traspaso_documento: this.form.get('traspaso_documento')?.value || null,
          ...this.buildTraspasoComputedFields(),
          descuento: 0,
          nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
          nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
        });
      } else {
        // Pago completo: generar un pago por cada mora seleccionada
        const puMora = Number(this.pu || 0);
        for (let i = 0; i < cant; i++) {
          pagos.push({
            id_forma_cobro: this.form.get('metodo_pago')?.value || null,
            nro_cobro: (this.baseNro || 1) + i,
            monto: puMora,
            fecha_cobro: hoy,
            observaciones: this.composeObservaciones(),
            pu_mensualidad: puMora,
            detalle: `Pago de Mora ${i + 1}`,
            tipo_pago: 'MORA',
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
            traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
            traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
            traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
            traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
            traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
            traspaso_documento: this.form.get('traspaso_documento')?.value || null,
            ...this.buildTraspasoComputedFields(),
            descuento: 0,
            nro_factura: this.form.get('comprobante')?.value === 'FACTURA' ? (this.form.get('nro_factura')?.value || null) : null,
            nro_recibo: this.form.get('comprobante')?.value === 'RECIBO' ? (this.form.get('nro_recibo')?.value || null) : null,
          });
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
        traspaso_gestion: this.form.get('traspaso_gestion')?.value || null,
        traspaso_nro_cuota: this.form.get('traspaso_nro_cuota')?.value || null,
        traspaso_cod_est: this.form.get('traspaso_cod_est')?.value || null,
        traspaso_fecha_origen: this.form.get('traspaso_fecha_origen')?.value || null,
        traspaso_carrera_origen: this.form.get('traspaso_carrera_origen')?.value || null,
        traspaso_documento: this.form.get('traspaso_documento')?.value || null,
        ...this.buildTraspasoComputedFields(),
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

    console.log('[MensualidadModal] ===== PAYLOAD FINAL ANTES DE EMITIR =====');
    console.log('[MensualidadModal] Total de pagos:', pagos.length);
    pagos.forEach((pago, idx) => {
      console.log(`[MensualidadModal] Pago ${idx + 1}:`, {
        detalle: pago.detalle,
        cod_tipo_cobro: pago.cod_tipo_cobro,
        tipo_pago: pago.tipo_pago,
        monto: pago.monto,
        pu_mensualidad: pago.pu_mensualidad,
        numero_cuota: pago.numero_cuota,
        id_asignacion_mora: pago.id_asignacion_mora,
        id_asignacion_costo: pago.id_asignacion_costo,
        fecha_deposito: pago.fecha_deposito
      });
    });
    console.log('[MensualidadModal] ===== FIN PAYLOAD =====');

    // Si es TARJETA / CHEQUE / DEPÓSITO / TRANSFERENCIA, enviar además cabecera con la cuenta bancaria
    // QR no requiere cabecera bancaria (se gestiona en el flujo QR)
    if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferenciaBancaria) {
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
    if (this.isTarjeta || this.isCheque || this.isDeposito || this.isTransferenciaBancaria) {
      this.resetTarjetaFields();
    }
    if (this.isTraspaso) {
      this.resetTraspasoFields();
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
      traspaso_gestion: 'Gestión',
      traspaso_nro_cuota: 'Nro Cuota',
      traspaso_cod_est: 'Código Est.',
      traspaso_fecha_origen: 'Fecha Origen',
      traspaso_carrera_origen: 'Carrera Origen',
      traspaso_documento: 'Documento',
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
        try { c.markAsTouched(); } catch { }
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
      ['id_cuentas_bancarias', 'fecha_deposito', 'nro_deposito', 'banco_origen', 'tarjeta_first4', 'tarjeta_last4'].forEach(addIfMissing);
    } else if (this.isTransferenciaBancaria) {
      ['id_cuentas_bancarias', 'fecha_deposito', 'nro_deposito', 'banco_origen'].forEach(addIfMissing);
    } else if (this.isCheque || this.isDeposito) {
      ['id_cuentas_bancarias', 'fecha_deposito', 'nro_deposito'].forEach(addIfMissing);
    } else if (this.isTraspaso) {
      ['traspaso_gestion', 'traspaso_nro_cuota', 'traspaso_cod_est', 'traspaso_fecha_origen', 'traspaso_carrera_origen', 'traspaso_documento'].forEach(addIfMissing);
    }
    return out;
  }

  private resetTarjetaFields(): void {
    const names = ['banco_origen', 'tarjeta_first4', 'tarjeta_last4', 'id_cuentas_bancarias', 'fecha_deposito', 'nro_deposito'];
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

  private resetTraspasoFields(): void {
    const names = ['traspaso_gestion', 'traspaso_nro_cuota', 'traspaso_cod_est', 'traspaso_fecha_origen', 'traspaso_carrera_origen', 'traspaso_documento'];
    for (const n of names) {
      const c = this.form.get(n);
      if (!c) continue;
      c.setValue('', { emitEvent: false });
      c.markAsPristine();
      c.markAsUntouched();
      c.clearValidators();
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
    if (this.isCheque || this.isDeposito || this.isTransferenciaBancaria) {
      const idCuenta = this.form.get('id_cuentas_bancarias');
      const fecha = this.form.get('fecha_deposito');
      const nro = this.form.get('nro_deposito');
      idCuenta?.updateValueAndValidity({ emitEvent: false });
      fecha?.updateValueAndValidity({ emitEvent: false });
      nro?.updateValueAndValidity({ emitEvent: false });
      if (!idCuenta?.value || !fecha?.value || !nro?.value) return false;
    }
    if (this.isTraspaso) {
      const trFields = ['traspaso_gestion', 'traspaso_nro_cuota', 'traspaso_cod_est', 'traspaso_fecha_origen', 'traspaso_carrera_origen', 'traspaso_documento'];
      for (const n of trFields) {
        const c = this.form.get(n);
        c?.updateValueAndValidity({ emitEvent: false });
        if (!c?.value) return false;
      }
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
        if (!isOnOrBeforeDeadlineLocal(this.descuentoSemestreFechaLimite)) return 0;
      }

      if (!this.descuentoSemestreIdDefDescuento || this.descuentoSemestreIdDefDescuento <= 0) return 0;

      const totalCuotasPendientes = this.pendientes || 0;
      // El descuento solo aplica si se seleccionan las 5 cuotas del semestre completo de una sola vez
      if (cantidadSeleccionada !== 5) return 0;

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

  pagarMoraPendiente(): void {
    if (!this.moraPendienteDetectada) return;

    const esParcial = !!this.form.get('pago_parcial')?.value;
    const hoy = this.form.get('fecha_cobro')?.value || new Date().toISOString().slice(0, 10);
    const compSelRaw = this.form.get('comprobante')?.value;
    const compSel = compSelRaw;
    const tipo_documento = compSel === 'FACTURA' ? 'F' : (compSel === 'RECIBO' ? 'R' : '');
    const medio_doc = (this.form.get('computarizada')?.value === 'MANUAL') ? 'M' : 'C';

    const moraItem = this.buildPagoMoraItem(this.moraPendienteDetectada, hoy, compSel, tipo_documento, medio_doc);
    moraItem.nro_cobro = this.baseNro || 1;

    // Si es pago parcial, usar el monto ingresado
    if (esParcial) {
      const montoParcial = Number(this.form.get('monto_parcial')?.value || 0);
      if (montoParcial <= 0) {
        this.modalAlertMessage = 'Debe ingresar un monto válido para el pago parcial.';
        this.modalAlertType = 'error';
        return;
      }
      const moraNetoTotal = this.recalcularMoraConFechaDeposito(this.moraPendienteDetectada);
      if (montoParcial > moraNetoTotal) {
        this.modalAlertMessage = `El monto parcial no puede ser mayor a ${moraNetoTotal} Bs.`;
        this.modalAlertType = 'error';
        return;
      }
      moraItem.monto = montoParcial;
      moraItem.pu_mensualidad = montoParcial;
    }

    const pagos = [moraItem];
    this.addPagos.emit(pagos);

    // Cerrar modal
    const modalEl = document.getElementById('mensualidadModal');
    const bs = (window as any).bootstrap;
    if (modalEl && bs?.Modal) {
      const instance = bs.Modal.getInstance(modalEl) || new bs.Modal(modalEl);
      instance.hide();
    }
    this.showOrdenPagoInfo = false;
    this.moraPendienteDetectada = null;
  }
}
