import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import {
  RecepcionIngresosService,
  InitialData,
} from '../../../../services/recepcion-ingresos.service';

interface FilaOtrosIngresosCf {
  usuario_libro: string;
  cod_libro_diario: string;
  fecha_libro: string;
  total_deposito: number;
  total_traspaso: number;
  total_recibos: number;
  total_facturas: number;
}

@Component({
  selector: 'app-otros-ingresos-cf',
  standalone: true,
  imports: [CommonModule, FormsModule, DecimalPipe, RouterModule],
  templateUrl: './otros-ingresos-cf.component.html',
  styleUrls: ['./otros-ingresos-cf.component.scss'],
})
export class OtrosIngresosCfComponent implements OnInit {

  catalogos: InitialData = {
    carreras: [], actividades: [], cajas: [], tesoreros: [],
    usuarios_activos: [], usuarios_libros: [],
  };

  // Formulario principal
  carrera = '';
  idActividad: number | null = null;
  idCajaActividad: number | null = null;
  fechaRecepcion = this.hoyIso();
  entregue1 = '';
  entregue2 = '';
  recibi1 = '';
  recibi2 = '';
  observacion = '';

  // Correlativo (encabezado)
  correlativo = '';
  numDocumento = 0;
  correlativoStr = '';

  // Filas de detalle
  filas: FilaOtrosIngresosCf[] = [];

  // Modal
  modalAbierto = false;
  modalUsuario = '';
  modalFecha = '';
  modalMonto: number | null = null;
  modalCodLibro = '';

  // Estado
  cargando = false;
  guardando = false;
  alertMsg = '';
  alertOk = true;

  // Flags validación formulario principal
  invalidCarrera = false;
  invalidActividad = false;
  invalidCaja = false;
  invalidEntregue1 = false;
  invalidRecibi1 = false;

  // Flags validación modal
  invalidModalUsuario = false;
  invalidModalMonto = false;

  constructor(private readonly svc: RecepcionIngresosService) {}

  ngOnInit(): void {
    void this.cargarCatalogos();
  }

  async cargarCatalogos(): Promise<void> {
    this.cargando = true;
    try {
      const res = await firstValueFrom(this.svc.initialData());
      this.catalogos = res.data;
    } catch {
      this.toast('Error al cargar catálogos.', false);
    } finally {
      this.cargando = false;
    }
  }

  async actualizarCorrelativo(): Promise<void> {
    if (!this.carrera || !this.fechaRecepcion) {
      this.correlativo = '';
      return;
    }
    try {
      const res = await firstValueFrom(this.svc.siguienteNumDocumento(this.carrera, this.fechaRecepcion));
      this.numDocumento = res.data?.num_documento ?? 0;
      this.correlativo = res.data?.cod_documento ?? '';
      this.correlativoStr = String(this.numDocumento).padStart(2, '0');
    } catch {
      this.correlativo = '';
    }
  }

  onActividadChange(id: number | null): void {
    this.invalidActividad = false;
    if (!id) return;

    const actividad = this.catalogos.actividades.find(a => a.id === id);
    if (!actividad) return;

    const desc = actividad.descripcion.toUpperCase();
    if (desc.includes('EEA')) {
      this.carrera = 'EEA';
    } else if (desc.includes('MEA')) {
      this.carrera = 'MEA';
    }

    if (this.carrera) {
      this.invalidCarrera = false;
      void this.actualizarCorrelativo();
    }
  }

  onCorrelativoChange(val: string): void {
    this.correlativoStr = val;
    this.numDocumento = parseInt(val, 10) || 0;
  }

  onCorrelativoBlur(): void {
    const n = this.numDocumento;
    this.correlativoStr = n > 0 ? String(n).padStart(2, '0') : '';
  }

  // ─── Modal ───────────────────────────────────────────────────────────────────

  abrirModal(): void {
    this.invalidCarrera    = !this.carrera;
    this.invalidActividad  = !this.idActividad;
    this.invalidCaja       = !this.idCajaActividad;
    this.invalidEntregue1  = !this.entregue1;
    this.invalidRecibi1    = !this.recibi1;

    if (this.invalidCarrera || this.invalidActividad || this.invalidCaja || this.invalidEntregue1 || this.invalidRecibi1) {
      this.toast('Complete los campos obligatorios antes de agregar un ingreso.', false);
      return;
    }

    this.modalUsuario = this.recibi1;
    this.modalFecha = this.fechaRecepcion;
    this.modalMonto = null;
    this.modalCodLibro = '';
    this.invalidModalUsuario = false;
    this.invalidModalMonto = false;
    this.modalAbierto = true;
    void this.generarCodLibroModal();
  }

  cerrarModal(): void {
    this.modalAbierto = false;
    this.modalUsuario = '';
    this.modalFecha = '';
    this.modalMonto = null;
    this.modalCodLibro = '';
    this.invalidModalUsuario = false;
    this.invalidModalMonto = false;
  }

  async generarCodLibroModal(): Promise<void> {
    if (!this.carrera || !this.fechaRecepcion) return;
    const mes = this.fechaRecepcion.substring(5, 7);
    const prefijo = `OI-${this.carrera}-${mes}-`;
    try {
      const res = await firstValueFrom(this.svc.correlativoDetalle(prefijo));
      const correlativo = (res.data?.correlativo ?? 1) + this.filas.length;
      this.modalCodLibro = prefijo + String(correlativo).padStart(3, '0');
    } catch (err) {
      console.error('correlativoDetalle error:', err);
      this.modalCodLibro = '';
    }
  }

  agregarFila(): void {
    this.invalidModalUsuario = !this.modalUsuario;
    this.invalidModalMonto = !this.modalMonto || this.modalMonto <= 0;

    if (this.invalidModalUsuario || this.invalidModalMonto) return;

    const codLibro = this.modalCodLibro || `OI-${this.carrera}-${this.fechaRecepcion.substring(5, 7)}-???`;

    this.filas = [...this.filas, {
      usuario_libro: this.modalUsuario,
      cod_libro_diario: codLibro,
      fecha_libro: this.modalFecha || this.fechaRecepcion,
      total_deposito: 0,
      total_traspaso: 0,
      total_recibos: this.modalMonto!,
      total_facturas: 0,
    }];

    this.cerrarModal();
  }

  eliminarFila(index: number): void {
    this.filas = this.filas.filter((_, i) => i !== index);
  }

  totalGeneral(): number {
    return this.filas.reduce(
      (sum, f) => sum + f.total_deposito + f.total_traspaso + f.total_recibos + f.total_facturas, 0
    );
  }

  // ─── Registro ────────────────────────────────────────────────────────────────

  private validar(): boolean {
    this.invalidCarrera = !this.carrera;
    this.invalidActividad = !this.idActividad;
    this.invalidCaja = !this.idCajaActividad;
    this.invalidEntregue1 = !this.entregue1;
    this.invalidRecibi1 = !this.recibi1;

    if (this.invalidCarrera || this.invalidActividad || this.invalidCaja || this.invalidEntregue1 || this.invalidRecibi1) {
      this.toast('Complete los campos obligatorios del formulario.', false);
      return false;
    }
    if (this.filas.length === 0) {
      this.toast('Debe agregar al menos una fila de ingreso.', false);
      return false;
    }
    return true;
  }

  async registrar(): Promise<void> {
    if (!this.validar()) return;

    this.guardando = true;
    try {
      await firstValueFrom(this.svc.registrar({
        codigo_carrera: this.carrera,
        fecha_recepcion: this.fechaRecepcion,
        usuario_entregue1: this.entregue1,
        usuario_recibi1: this.recibi1,
        usuario_entregue2: this.entregue2 || null,
        usuario_recibi2: this.recibi2 || null,
        id_actividad_economica: this.idActividad,
        id_caja_actividad: this.idCajaActividad,
        es_ingreso_libro_diario: false,
        observacion: this.observacion || null,
        detalles: this.filas.map(f => ({
          usuario_libro: f.usuario_libro,
          cod_libro_diario: f.cod_libro_diario,
          fecha_final_libros: f.fecha_libro,
          total_recibos: f.total_recibos,
          total_facturas: 0,
          total_deposito: 0,
          total_traspaso: 0,
        })),
      }));

      this.toast('Ingreso registrado correctamente.', true);
      this.limpiarFormulario();
      await this.actualizarCorrelativo();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al registrar.', false);
    } finally {
      this.guardando = false;
    }
  }

  private limpiarCampos(): void {
    this.idActividad = null;
    this.idCajaActividad = null;
    this.carrera = '';
    this.entregue1 = '';
    this.entregue2 = '';
    this.recibi1 = '';
    this.recibi2 = '';
    this.observacion = '';
    this.correlativo = '';
    this.numDocumento = 0;
    this.correlativoStr = '';
  }

  private limpiarFormulario(): void {
    this.limpiarCampos();
    this.filas = [];
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────────

  private hoyIso(): string {
    return new Date().toISOString().substring(0, 10);
  }

  private toast(msg: string, ok: boolean): void {
    this.alertMsg = msg;
    this.alertOk = ok;
    setTimeout(() => { this.alertMsg = ''; }, 6000);
  }

  labelUsuario(nickname: string): string {
    const u = this.catalogos.tesoreros.find(t => t.nickname === nickname);
    return u ? u.label : nickname;
  }
}
