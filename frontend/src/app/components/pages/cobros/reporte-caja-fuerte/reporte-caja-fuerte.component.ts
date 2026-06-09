import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import {
  ReporteCajaFuerteService,
  CajaActividad,
  DatosMovimientos,
  ReporteCFMensual,
} from '../../../../services/reporte-caja-fuerte.service';

@Component({
  selector: 'app-reporte-caja-fuerte',
  standalone: true,
  imports: [CommonModule, FormsModule, DecimalPipe],
  templateUrl: './reporte-caja-fuerte.component.html',
  styleUrls: ['./reporte-caja-fuerte.component.scss'],
})
export class ReporteCajaFuerteComponent implements OnInit {

  cajas: CajaActividad[] = [];

  // Filtros
  idCaja: number | null = null;
  mesAnio = this.mesActual();

  // Resultado
  datos: DatosMovimientos | null = null;
  reporteExistente: ReporteCFMensual | null = null;
  yaImpreso = false;

  // Estado UI
  cargando = false;
  generando = false;
  imprimiendo = false;
  alertMsg = '';
  alertOk = true;

  // Validación filtros
  invalidCaja = false;
  invalidMes = false;

  // Modal anulación
  modalAnulando = false;
  motivoAnulacion = '';
  invalidMotivo = false;

  constructor(private readonly svc: ReporteCajaFuerteService) {}

  ngOnInit(): void {
    void this.cargarCatalogos();
  }

  async cargarCatalogos(): Promise<void> {
    this.cargando = true;
    try {
      const res = await firstValueFrom(this.svc.initialData());
      this.cajas = res.cajas;
    } catch {
      this.toast('Error al cargar catálogos.', false);
    } finally {
      this.cargando = false;
    }
  }

  async generarReporte(): Promise<void> {
    this.invalidCaja = !this.idCaja;
    this.invalidMes  = !this.mesAnio;
    if (this.invalidCaja || this.invalidMes) return;

    this.generando = true;
    this.datos = null;
    this.reporteExistente = null;
    this.yaImpreso = false;

    try {
      this.datos = await firstValueFrom(this.svc.getMovimientos(this.mesAnio, this.idCaja!));

      // Verificar si ya existe reporte para este mes
      const verif = await firstValueFrom(this.svc.verificar(this.mesAnio, this.idCaja!));
      if (verif.existe && !verif.anulado) {
        this.reporteExistente = verif.reporte;
        this.yaImpreso = true;
      }
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al generar el reporte.', false);
    } finally {
      this.generando = false;
    }
  }

  async imprimir(): Promise<void> {
    if (!this.datos || !this.idCaja) return;

    this.imprimiendo = true;
    try {
      const res = await firstValueFrom(
        this.svc.imprimir(this.mesAnio, this.idCaja, this.datos.saldo_final, this.yaImpreso)
      );
      this.reporteExistente = res.reporte;
      this.yaImpreso = true;
      window.open(res.url, '_blank');
      this.toast('Reporte guardado. PDF abierto en nueva pestaña.', true);
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al imprimir el reporte.', false);
    } finally {
      this.imprimiendo = false;
    }
  }

  abrirModalAnular(): void {
    this.motivoAnulacion = '';
    this.invalidMotivo = false;
    this.modalAnulando = true;
  }

  cerrarModalAnular(): void {
    this.modalAnulando = false;
  }

  async confirmarAnulacion(): Promise<void> {
    this.invalidMotivo = !this.motivoAnulacion.trim();
    if (this.invalidMotivo) return;

    try {
      await firstValueFrom(this.svc.anular(this.reporteExistente!.codigo_reporte, this.motivoAnulacion));
      this.toast('Reporte anulado correctamente.', true);
      this.cerrarModalAnular();
      this.datos = null;
      this.reporteExistente = null;
      this.yaImpreso = false;
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al anular.', false);
    }
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────────

  private mesActual(): string {
    return new Date().toISOString().substring(0, 7);
  }

  private toast(msg: string, ok: boolean): void {
    this.alertMsg = msg;
    this.alertOk = ok;
    setTimeout(() => { this.alertMsg = ''; }, 6000);
  }

  get mesFuturo(): boolean {
    if (!this.mesAnio) return false;
    return this.mesAnio > new Date().toISOString().substring(0, 7);
  }

  get fechaFinMesAnterior(): string {
    if (!this.mesAnio) return '';
    const [anio, mes] = this.mesAnio.split('-').map(Number);
    const ultimo = new Date(anio, mes - 1, 0);
    return ultimo.toISOString().substring(0, 10);
  }

  get totalFilas(): number {
    return this.datos?.movimientos.length ?? 0;
  }

  /** "abril-2026", "mayo-2026", etc. */
  get labelMes(): string {
    const [anio, mes] = this.mesAnio.split('-');
    const nombre = new Date(+anio, +mes - 1, 1)
      .toLocaleString('es', { month: 'long' });
    return `${nombre}-${anio}`;
  }
}
