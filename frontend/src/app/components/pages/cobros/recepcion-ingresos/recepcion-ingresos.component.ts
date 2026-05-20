import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterModule, NavigationEnd } from '@angular/router';
import { firstValueFrom, Subscription } from 'rxjs';
import { filter } from 'rxjs/operators';
import {
  RecepcionIngresosService,
  InitialData,
  FilaListaRecepcionSga,
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
export class RecepcionIngresosComponent implements OnInit, OnDestroy {

  private navSub?: Subscription;
  pestana: 'recepcion' | 'lista' = 'recepcion';

  // Lista recepción (SGA lista_recepcion)
  listaRecepcion: FilaListaRecepcionSga[] = [];
  mostrarModalAnular = false;
  anularId: number | null = null;
  anularCodDocumento = '';
  motivoAnulacion = '';
  anulando = false;
  // Catálogos
  catalogos: InitialData = {
    carreras: [], actividades: [], cajas: [], tesoreros: [],
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

  /** Bordes / Bootstrap is-invalid: consulta (Consultar) */
  invalidCarrera = false;
  invalidFechaDesde = false;
  invalidFechaHasta = false;
  invalidFechaRango = false;
  invalidActividadConsulta = false;
  /** Documento: Vista previa / Imprimir */
  invalidFechaRecepcion = false;
  invalidEntregue1 = false;
  invalidRecibi1 = false;
  invalidActividadDocumento = false;

  constructor(
    private readonly svc: RecepcionIngresosService,
    private readonly router: Router,
  ) {}

  ngOnInit(): void {
    void this.cargarCatalogos();
    this.syncPestanaConUrl();

    this.navSub = this.router.events
      .pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd))
      .subscribe(() => this.syncPestanaConUrl());
  }

  ngOnDestroy(): void {
    this.navSub?.unsubscribe();
  }

  /** Misma pantalla en dos rutas: Angular reutiliza el componente; hay que leer la URL en cada navegación. */
  private syncPestanaConUrl(): void {
    const path = this.router.url.split('?')[0];
    const esLista = path.includes('economico/lista-recepcion');
    const antes = this.pestana;
    this.pestana = esLista ? 'lista' : 'recepcion';
    if (this.pestana === 'lista' && antes !== 'lista') {
      void this.cargarListaRecepcion();
    }
  }

  irFormularioRecepcion(): void {
    void this.router.navigateByUrl('/cobros/recepcion-ingresos');
  }

  irListaRecepcion(): void {
    void this.router.navigateByUrl('/economico/lista-recepcion');
  }

  async cargarListaRecepcion(): Promise<void> {
    try {
      this.listaRecepcion = await firstValueFrom(this.svc.listarTablaSga());
    } catch {
      this.listaRecepcion = [];
      this.toast('Error al cargar la lista de recepciones.', false);
    }
  }

  abrirModalAnular(row: FilaListaRecepcionSga): void {
    this.anularId = row.id_recepcion;
    this.anularCodDocumento = row.cod_documento;
    this.motivoAnulacion = '';
    this.mostrarModalAnular = true;
  }

  cerrarModalAnular(): void {
    this.mostrarModalAnular = false;
    this.anularId = null;
    this.motivoAnulacion = '';
  }

  async confirmarAnulacion(): Promise<void> {
    if (!this.motivoAnulacion?.trim()) {
      this.toast('Debe ingresar un motivo de anulacion para poder anular', false);
      return;
    }
    if (this.anularId == null) {
      return;
    }
    this.anulando = true;
    try {
      await firstValueFrom(this.svc.anular(this.anularId, this.motivoAnulacion.trim()));
      this.cerrarModalAnular();
      await this.cargarListaRecepcion();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al anular.', false);
    } finally {
      this.anulando = false;
    }
  }

  async imprimirRecepcionLista(idRecepcion: number): Promise<void> {
    try {
      const pdf = await firstValueFrom(this.svc.documentoPdf(idRecepcion));
      if (!pdf?.success || !pdf.url) {
        window.alert('No se pudo generar el documento PDF por favor contacte con el administrador del sistema.');
        return;
      }
      window.open(pdf.url, '_blank', 'noopener');
    } catch {
      window.alert('No se pudo generar el documento PDF por favor contacte con el administrador del sistema.');
    }
  }

  /** Misma semántica que SGA lista_recepcion (row.anulado == 'f' → activa). Tolera JSON booleano/numérico. */
  esListaNoAnulada(row: FilaListaRecepcionSga): boolean {
    return !this.esRecepcionAnuladaLista(row);
  }

  private esRecepcionAnuladaLista(row: FilaListaRecepcionSga): boolean {
    const a = row.anulado as unknown;
    if (a === false || a === 0 || a === '0' || a === 'f' || a === 'F') {
      return false;
    }
    if (a === true || a === 1 || a === '1' || a === 't' || a === 'T') {
      return true;
    }
    if (typeof a === 'string') {
      const s = a.trim().toLowerCase();
      if (s === 'false' || s === 'no') {
        return false;
      }
      if (s === 'true' || s === 'yes' || s === 'si' || s === 'sí') {
        return true;
      }
    }
    return false;
  }

  /** Texto mostrado al pasar el mouse sobre el ícono (motivo / observación de anulación). */
  textoMotivoAnulacionLista(row: FilaListaRecepcionSga): string {
    const m = row.motivo_anulacion?.trim() ?? '';
    return m !== '' ? m : 'Sin observación de anulación registrada.';
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

      this.limpiarFlagsConsulta();
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
          fecha_inicial_libros: this.fechaDesde,
          fecha_final_libros: this.fechaHasta,
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
    this.limpiarTodasFlagsInvalido();
  }

  private isActividadElegida(): boolean {
    const v = this.idActividad;
    if (v === null || v === undefined) {
      return false;
    }
    if (typeof v === 'string' && (v === '' || v === 'null')) {
      return false;
    }
    const n = Number(v);

    return !Number.isNaN(n) && n > 0;
  }

  private limpiarFlagsConsulta(): void {
    this.invalidCarrera = false;
    this.invalidFechaDesde = false;
    this.invalidFechaHasta = false;
    this.invalidFechaRango = false;
    this.invalidActividadConsulta = false;
  }

  private limpiarFlagsDocumento(): void {
    this.invalidFechaRecepcion = false;
    this.invalidEntregue1 = false;
    this.invalidRecibi1 = false;
    this.invalidActividadDocumento = false;
  }

  private limpiarTodasFlagsInvalido(): void {
    this.limpiarFlagsConsulta();
    this.limpiarFlagsDocumento();
  }

  private validarBusqueda(): boolean {
    this.limpiarFlagsConsulta();

    this.invalidCarrera = !this.carrera?.trim();
    this.invalidFechaDesde = !this.fechaDesde;
    this.invalidFechaHasta = !this.fechaHasta;
    this.invalidActividadConsulta = !this.isActividadElegida();
    this.invalidFechaRango = !!(
      this.fechaDesde
      && this.fechaHasta
      && this.fechaDesde > this.fechaHasta
    );
    if (this.invalidFechaRango) {
      this.invalidFechaDesde = true;
      this.invalidFechaHasta = true;
    }

    if (this.invalidFechaRango) {
      this.toast(
        'Rango inválido: "Fecha inicial libro" no puede ser mayor que "Fecha final libro".',
        false,
      );
      return false;
    }

    const faltan: string[] = [];
    if (this.invalidCarrera) {
      faltan.push('Carrera');
    }
    if (this.invalidFechaDesde) {
      faltan.push('Fecha inicial libro');
    }
    if (this.invalidFechaHasta) {
      faltan.push('Fecha final libro');
    }
    if (this.invalidActividadConsulta) {
      faltan.push('Actividad');
    }
    if (faltan.length) {
      this.toast(`Seleccione: ${faltan.join(', ')}.`, false);
      return false;
    }
    return true;
  }

  private validarFirmas(): boolean {
    this.limpiarFlagsDocumento();

    this.invalidFechaRecepcion = !this.fechaRecepcion;
    this.invalidEntregue1 = !this.entregue1?.trim();
    this.invalidRecibi1 = !this.recibi1?.trim();
    this.invalidActividadDocumento = !this.isActividadElegida();

    const faltan: string[] = [];
    if (this.invalidFechaRecepcion) {
      faltan.push('Fecha de recepción');
    }
    if (this.invalidActividadDocumento) {
      faltan.push('Actividad');
    }
    if (this.invalidEntregue1) {
      faltan.push('Entregue 1');
    }
    if (this.invalidRecibi1) {
      faltan.push('Recibí 1');
    }
    if (faltan.length) {
      this.toast(`Seleccione: ${faltan.join(', ')}.`, false);
      return false;
    }
    return true;
  }

  /** Coincide con la columna (a+b): suma depósito + traspaso + recibos + facturas por fila. */
  get totalGeneral(): number {
    return this.filas.reduce(
      (s, f) =>
        s +
        f.total_deposito +
        f.total_traspaso +
        f.total_recibos +
        f.total_facturas,
      0
    );
  }
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
