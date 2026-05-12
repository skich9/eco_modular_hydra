import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import {
  EgresoCajaFuerteService,
  CajaActividad,
  EgresoCajaFuerte,
} from '../../../../services/egreso-caja-fuerte.service';

@Component({
  selector: 'app-egreso-caja-fuerte',
  standalone: true,
  imports: [CommonModule, FormsModule, DecimalPipe],
  templateUrl: './egreso-caja-fuerte.component.html',
  styleUrls: ['./egreso-caja-fuerte.component.scss'],
})
export class EgresoCajaFuerteComponent implements OnInit {

  cajas: CajaActividad[] = [];
  egresos: EgresoCajaFuerte[] = [];

  // Formulario nuevo registro
  idCajaSeleccionada: number | null = null;
  prefijoCaja = '';
  correlativoNumero: number | null = null;
  monto: number | null = null;
  fechaEgreso = this.hoyIso();
  descripcion = '';
  observacion = '';

  // Edición inline
  editandoCodigo: number | null = null;
  editCaja: number | null = null;
  editPrefijo = '';
  editCorrelativoNumero: number | null = null;
  editMonto: number | null = null;
  editFecha = '';
  editDescripcion = '';
  editObservacion = '';

  // Modal de anulación
  mostrarModalEliminar = false;
  motivoEliminacion = '';
  egresoAEliminar: EgresoCajaFuerte | null = null;

  // Estado
  cargando = false;
  guardando = false;
  alertMsg = '';
  alertOk = true;

  // Flags de validación
  invalidCaja = false;
  invalidCorrelativo = false;
  invalidMonto = false;
  invalidFecha = false;
  invalidDescripcion = false;

  constructor(private readonly svc: EgresoCajaFuerteService) {}

  ngOnInit(): void {
    void this.cargarDatos();
  }

  private async cargarDatos(): Promise<void> {
    this.cargando = true;
    try {
      const res = await firstValueFrom(this.svc.initialData());
      this.cajas = res.data.cajas;
      this.egresos = res.data.egresos;
    } catch {
      this.toast('Error al cargar datos iniciales.', false);
    } finally {
      this.cargando = false;
    }
  }

  // ─── Prefijo ────────────────────────────────────────────────────────────────

  cargaPrefijo(): void {
    const caja = this.cajas.find(c => c.id_caja_actividad === Number(this.idCajaSeleccionada));
    if (!caja) { this.prefijoCaja = ''; return; }
    const fecha = this.fechaEgreso || this.hoyIso();
    const [yyyy, mm] = fecha.split('-');
    this.prefijoCaja = `${caja.prefijo}-EG-${yyyy}-${mm}`;
  }

  cargaPrefijoEdicion(): void {
    const caja = this.cajas.find(c => c.id_caja_actividad === Number(this.editCaja));
    if (!caja) { this.editPrefijo = ''; return; }
    const fecha = this.editFecha || this.hoyIso();
    const [yyyy, mm] = fecha.split('-');
    this.editPrefijo = `${caja.prefijo}-EG-${yyyy}-${mm}`;
  }

  onFechaChange(): void {
    if (this.idCajaSeleccionada) this.cargaPrefijo();
  }

  onFechaEditChange(): void {
    if (this.editCaja) this.cargaPrefijoEdicion();
  }

  // ─── Registrar ───────────────────────────────────────────────────────────────

  async registrar(): Promise<void> {
    if (!this.validarFormulario()) return;

    this.guardando = true;
    try {
      const correlativo = `${this.prefijoCaja}-${this.correlativoNumero}`;
      await firstValueFrom(this.svc.registrar({
        correlativo,
        id_caja_actividad: Number(this.idCajaSeleccionada),
        fecha_egreso: this.fechaEgreso,
        monto: Number(this.monto),
        descripcion: this.descripcion.trim(),
        observacion: this.observacion.trim() || null,
      }));
      this.toast('Egreso registrado correctamente.', true);
      this.limpiarFormulario();
      await this.cargarDatos();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al registrar el egreso.', false);
    } finally {
      this.guardando = false;
    }
  }

  // ─── Edición inline ──────────────────────────────────────────────────────────

  iniciarEdicion(egreso: EgresoCajaFuerte): void {
    this.editandoCodigo = egreso.codigo_egreso;
    this.editCaja = egreso.id_caja_actividad;
    this.editFecha = typeof egreso.fecha_egreso === 'string'
      ? egreso.fecha_egreso.substring(0, 10)
      : this.hoyIso();
    this.editMonto = Number(egreso.monto);
    this.editDescripcion = egreso.descripcion;
    this.editObservacion = egreso.observacion ?? '';

    // Reconstruir prefijo y número desde el correlativo guardado
    const partes = egreso.correlativo.split('-');
    const ultimaParte = partes[partes.length - 1];
    const numParsed = parseInt(ultimaParte, 10);
    this.editCorrelativoNumero = !isNaN(numParsed) ? numParsed : null;
    this.cargaPrefijoEdicion();
  }

  cancelarEdicion(): void {
    this.editandoCodigo = null;
  }

  async guardarEdicion(egreso: EgresoCajaFuerte): Promise<void> {
    if (!this.validarEdicion()) return;

    this.guardando = true;
    try {
      const correlativo = `${this.editPrefijo}-${this.editCorrelativoNumero}`;
      await firstValueFrom(this.svc.editar({
        codigo_egreso: egreso.codigo_egreso,
        correlativo,
        id_caja_actividad: Number(this.editCaja),
        fecha_egreso: this.editFecha,
        monto: Number(this.editMonto),
        descripcion: this.editDescripcion.trim(),
        observacion: this.editObservacion.trim() || null,
      }));
      this.toast('Egreso actualizado correctamente.', true);
      this.cancelarEdicion();
      await this.cargarDatos();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al actualizar el egreso.', false);
    } finally {
      this.guardando = false;
    }
  }

  // ─── Eliminar (modal) ────────────────────────────────────────────────────────

  abrirModalEliminar(egreso: EgresoCajaFuerte): void {
    this.egresoAEliminar = egreso;
    this.motivoEliminacion = '';
    this.mostrarModalEliminar = true;
  }

  cerrarModalEliminar(): void {
    this.mostrarModalEliminar = false;
    this.egresoAEliminar = null;
    this.motivoEliminacion = '';
  }

  async confirmarEliminar(): Promise<void> {
    if (!this.egresoAEliminar || !this.motivoEliminacion.trim()) return;

    this.guardando = true;
    try {
      await firstValueFrom(this.svc.eliminar({
        codigo_egreso: this.egresoAEliminar.codigo_egreso,
        motivo_anulacion: this.motivoEliminacion.trim(),
      }));
      this.toast('Egreso anulado correctamente.', true);
      this.cerrarModalEliminar();
      await this.cargarDatos();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al anular el egreso.', false);
    } finally {
      this.guardando = false;
    }
  }

  // ─── Validaciones ────────────────────────────────────────────────────────────

  private validarFormulario(): boolean {
    this.invalidCaja = !this.idCajaSeleccionada;
    this.invalidCorrelativo = !this.correlativoNumero || this.correlativoNumero <= 0;
    this.invalidMonto = !this.monto || this.monto <= 0;
    this.invalidFecha = !this.fechaEgreso;
    this.invalidDescripcion = !this.descripcion.trim();

    const faltan: string[] = [];
    if (this.invalidCaja) faltan.push('Caja');
    if (this.invalidCorrelativo) faltan.push('Número de correlativo');
    if (this.invalidMonto) faltan.push('Monto');
    if (this.invalidFecha) faltan.push('Fecha');
    if (this.invalidDescripcion) faltan.push('Descripción');

    if (faltan.length) {
      this.toast(`Complete los campos: ${faltan.join(', ')}.`, false);
      return false;
    }
    return true;
  }

  private validarEdicion(): boolean {
    const faltan: string[] = [];
    if (!this.editCaja) faltan.push('Caja');
    if (!this.editCorrelativoNumero || this.editCorrelativoNumero <= 0) faltan.push('Número de correlativo');
    if (!this.editFecha) faltan.push('Fecha');
    if (!this.editMonto || this.editMonto <= 0) faltan.push('Monto');
    if (!this.editDescripcion.trim()) faltan.push('Descripción');

    if (faltan.length) {
      this.toast(`Complete los campos: ${faltan.join(', ')}.`, false);
      return false;
    }
    return true;
  }

  // ─── Utilidades ──────────────────────────────────────────────────────────────

  private limpiarFormulario(): void {
    this.idCajaSeleccionada = null;
    this.prefijoCaja = '';
    this.correlativoNumero = null;
    this.monto = null;
    this.fechaEgreso = this.hoyIso();
    this.descripcion = '';
    this.observacion = '';
    this.invalidCaja = false;
    this.invalidCorrelativo = false;
    this.invalidMonto = false;
    this.invalidFecha = false;
    this.invalidDescripcion = false;
  }

  hoyIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  formatFecha(iso: string): string {
    if (!iso) return '';
    const s = iso.substring(0, 10);
    const [y, m, d] = s.split('-');
    return `${d}/${m}/${y}`;
  }

  nombreCaja(idCaja: number): string {
    return this.cajas.find(c => c.id_caja_actividad === idCaja)?.nombre_caja ?? '—';
  }

  private toast(msg: string, ok: boolean): void {
    this.alertMsg = msg;
    this.alertOk = ok;
    setTimeout(() => { if (this.alertMsg === msg) this.alertMsg = ''; }, 6000);
  }
}
