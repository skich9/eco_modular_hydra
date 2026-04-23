import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import {
  RecepcionIngresosService,
  InitialData,
} from '../../../../services/recepcion-ingresos.service';
export interface FilaReporte {
  usuario_libro: string;
  cod_libro_diario: string;
  fecha_inicial: string;
  fecha_final: string;
  total_deposito: number;
  total_traspaso: number;
  total_recibos: number;
  total_facturas: number;
  total_entregado: number;
}

@Component({
  selector: 'app-recepcion-ingresos',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, DecimalPipe],
  templateUrl: './recepcion-ingresos.component.html',
  styleUrls: ['./recepcion-ingresos.component.scss'],
})
export class RecepcionIngresosComponent implements OnInit {

  // Catálogos
  catalogos: InitialData = {
    carreras: [], actividades: [], tesoreros: [],
    usuarios_activos: [], usuarios_libros: [],
  };

  // Formulario
  carrera = '';
  idActividad: number | null = null;
  fechaDesde = this.hoyIso(); // Uso interno
  fechaHasta = this.hoyIso(); // Uso interno
  
  fechaRecepcion = this.hoyIso(); // Fecha física del documento
  
  entregue1 = '';
  recibi1 = '';
  entregue2 = '';
  recibi2 = '';
  observacion = '';

  // Estado
  cargando = false;
  generando = false;
  alertMsg = '';
  alertOk = true;

  // Reporte
  filas: FilaReporte[] = [];
  reporteGenerado = false;

  constructor(
    private readonly svc: RecepcionIngresosService,
  ) {}

  ngOnInit(): void {
    this.cargarCatalogos();
  }

  // ─── Eventos UI ────────────────────────────────────────────────────────
  


  private async cargarCatalogos(): Promise<void> {
    this.cargando = true;
    try {
      const res = await firstValueFrom(this.svc.initialData());
      this.catalogos = res.data;
    } catch {
      this.toast('Error al cargar datos iniciales.', false);
    } finally {
      this.cargando = false;
    }
  }

  // ─── Generar / Consultar cobros ────────────────────────────────────────

  async generarReporte(): Promise<void> {
    if (!this.validarBusqueda()) return;

    this.generando = true;
    this.reporteGenerado = false;
    this.filas = [];

    try {
      const res = await firstValueFrom(
        this.svc.generarReporte({
          codigo_carrera: this.carrera,
          fecha_desde: this.fechaDesde,
          fecha_hasta: this.fechaHasta,
          id_actividad_economica: this.idActividad ?? undefined,
          usuario_entregue1: this.entregue1,
          usuario_recibi1: this.recibi1,
          usuario_entregue2: this.entregue2 || undefined,
          usuario_recibi2: this.recibi2 || undefined,
        })
      );

      this.filas = (res.data?.detalles ?? []).map((d: any) => ({
        usuario_libro: d.usuario_libro ?? '—',
        cod_libro_diario: d.cod_libro_diario ?? '—',
        fecha_inicial: d.fecha_inicial_libros ?? this.fechaDesde,
        fecha_final: d.fecha_final_libros ?? this.fechaHasta,
        total_deposito: Number(d.total_deposito ?? 0),
        total_traspaso: Number(d.total_traspaso ?? 0),
        total_recibos: Number(d.total_recibos ?? 0),
        total_facturas: Number(d.total_facturas ?? 0),
        total_entregado: Number(d.total_entregado ?? 0),
      }));

      this.reporteGenerado = true;

      if (this.filas.length === 0) {
        this.toast('No hay cobros registrados en ese período y carrera.', false);
      } else {
        this.toast(`Reporte generado: ${this.filas.length} registros obtenidos.`, true);
      }
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al generar reporte.', false);
    } finally {
      this.generando = false;
    }
  }

  // ─── Guardar Recepción ──────────────────────────────────────────────────

  async guardarRecepcion(): Promise<void> {
    if (!this.reporteGenerado || this.filas.length === 0) return;
    if (!this.validarFirmas()) return;

    this.generando = true;
    try {
      const reg = await firstValueFrom(
        this.svc.registrar({
          codigo_carrera: this.carrera,
          fecha_recepcion: this.fechaRecepcion,
          usuario_entregue1: this.entregue1,
          usuario_recibi1: this.recibi1,
          usuario_entregue2: this.entregue2 || undefined,
          usuario_recibi2: this.recibi2 || undefined,
          id_actividad_economica: this.idActividad ?? undefined,
          observacion: this.observacion,
          detalles: this.filas.map(f => ({
            usuario_libro: f.usuario_libro,
            cod_libro_diario: f.cod_libro_diario,
            fecha_inicial_libros: f.fecha_inicial,
            fecha_final_libros: f.fecha_final,
            total_deposito: f.total_deposito,
            total_traspaso: f.total_traspaso,
            total_recibos: f.total_recibos,
            total_facturas: f.total_facturas,
            total_entregado: f.total_entregado,
          })),
        })
      );
      const nuevoId = reg?.data?.id;
      if (nuevoId != null) {
        const pdf = await firstValueFrom(this.svc.documentoPdf(Number(nuevoId)));
        if (pdf?.success && pdf.url) {
          window.open(pdf.url, '_blank', 'noopener');
          this.toast('Recepción registrada. Revise la descarga o pestaña del PDF.', true);
        } else {
          this.toast(pdf?.message ?? 'Recepción guardada, pero no se pudo generar el PDF.', false);
        }
      } else {
        this.toast('Recepción guardada, pero no se recibió el id del registro.', false);
      }
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al guardar.', false);
    } finally {
      this.generando = false;
    }
  }

  // ─── Utilidades ─────────────────────────────────────────────────────────

  limpiar(): void {
    this.carrera = '';
    this.idActividad = null;
    this.fechaRecepcion = this.hoyIso();
    this.fechaDesde = this.hoyIso();
    this.fechaHasta = this.hoyIso();

    this.entregue1 = '';
    this.recibi1 = '';
    this.entregue2 = '';
    this.recibi2 = '';
    this.observacion = '';
    this.filas = [];
    this.reporteGenerado = false;
    this.alertMsg = '';
  }

  private validarBusqueda(): boolean {
    if (!this.carrera) { this.toast('Selecciona una carrera primero.', false); return false; }
    if (!this.fechaDesde || !this.fechaHasta) { this.toast('Indica el rango de fechas válido.', false); return false; }
    if (this.fechaDesde > this.fechaHasta) { this.toast('Rango inválido: "Desde" es mayor que "Hasta".', false); return false; }
    return true;
  }
  
  private validarFirmas(): boolean {
    if (!this.entregue1 || !this.recibi1) { this.toast('Entregue 1 y Recibi 1 son obligatorios.', false); return false; }
    if (!this.fechaRecepcion) { this.toast('La fecha de recepción es requerida para el documento.', false); return false; }
    return true;
  }

  get totalGeneral(): number { return this.filas.reduce((s, f) => s + f.total_entregado, 0); }
  get totales() {
    return {
      deposito: this.filas.reduce((s, f) => s + f.total_deposito, 0),
      traspaso: this.filas.reduce((s, f) => s + f.total_traspaso, 0),
      recibos:  this.filas.reduce((s, f) => s + f.total_recibos, 0),
      facturas: this.filas.reduce((s, f) => s + f.total_facturas, 0),
    };
  }

  /** Igual criterio que SGA: nº de filas / cierres cargados en la tabla. */
  get nroReportesDiarios(): number {
    return this.filas.length;
  }

  async abrirVistaPrevia(): Promise<void> {
    if (this.filas.length === 0) {
      this.toast('No hay datos de recepción. Ejecute Consultar primero.', false);
      return;
    }
    if (!this.validarFirmas()) return;
    this.generando = true;
    try {
      const res = await firstValueFrom(
        this.svc.vistaPreviaPdf({
          fecha_recepcion: this.fechaRecepcion,
          fecha_inicial_libros: this.fechaDesde,
          fecha_final_libros: this.fechaHasta,
          observacion: this.observacion,
          usuario_entregue1: this.entregue1,
          usuario_recibi1: this.recibi1,
          usuario_entregue2: this.entregue2 || undefined,
          usuario_recibi2: this.recibi2 || undefined,
          id_actividad_economica: this.idActividad ?? null,
          detalles: this.filas.map(f => ({
            usuario_libro: f.usuario_libro,
            cod_libro_diario: f.cod_libro_diario,
            fecha_inicial_libros: f.fecha_inicial,
            fecha_final_libros: f.fecha_final,
            total_deposito: f.total_deposito,
            total_traspaso: f.total_traspaso,
            total_recibos: f.total_recibos,
            total_facturas: f.total_facturas,
            total_entregado: f.total_entregado,
          })),
        })
      );
      if (res?.success && res.url) {
        window.open(res.url, '_blank', 'noopener');
        this.toast('Vista previa generada. Revise la pestaña del PDF.', true);
      } else {
        this.toast(res?.message ?? 'No se pudo generar la vista previa.', false);
      }
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al generar vista previa PDF.', false);
    } finally {
      this.generando = false;
    }
  }

  hoyIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  }

  formatFecha(iso: string): string {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  }

  private toast(msg: string, ok: boolean): void {
    this.alertMsg = msg; this.alertOk = ok;
    setTimeout(() => { if (this.alertMsg === msg) this.alertMsg = ''; }, 6000);
  }
}
