import { Component, OnInit } from '@angular/core';
import { CommonModule, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import {
  ReporteCajaFuerteService,
  ReporteCFMensual,
} from '../../../../services/reporte-caja-fuerte.service';

@Component({
  selector: 'app-lista-reporte-caja-fuerte',
  standalone: true,
  imports: [CommonModule, FormsModule, DatePipe, DecimalPipe],
  templateUrl: './lista-reporte-caja-fuerte.component.html',
  styleUrls: ['./lista-reporte-caja-fuerte.component.scss'],
})
export class ListaReporteCajaFuerteComponent implements OnInit {

  reportes: ReporteCFMensual[] = [];

  // Estado UI
  cargando = false;
  alertMsg = '';
  alertOk = true;

  // Reimpresión
  reimpresionfila: ReporteCFMensual | null = null;
  reimprimiendo = false;

  // Modal anulación
  filaAnular: ReporteCFMensual | null = null;
  motivoAnulacion = '';
  invalidMotivo = false;
  anulando = false;

  constructor(private readonly svc: ReporteCajaFuerteService) {}

  ngOnInit(): void {
    void this.cargar();
  }

  async cargar(): Promise<void> {
    this.cargando = true;
    try {
      this.reportes = await firstValueFrom(this.svc.listar());
    } catch {
      this.toast('Error al cargar la lista de reportes.', false);
    } finally {
      this.cargando = false;
    }
  }

  // ─── Reimpresión ─────────────────────────────────────────────────────────────

  async reimprimir(reporte: ReporteCFMensual): Promise<void> {
    this.reimpresionfila = reporte;
    this.reimprimiendo = true;
    try {
      const fechaIni = reporte.fecha_inicio.substring(0, 7); // YYYY-MM
      const movData = await firstValueFrom(
        this.svc.getMovimientos(fechaIni, reporte.id_caja_actividad)
      );
      const res = await firstValueFrom(
        this.svc.imprimir(fechaIni, reporte.id_caja_actividad, movData.saldo_final, true)
      );
      window.open(res.url, '_blank');
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al reimprimir.', false);
    } finally {
      this.reimprimiendo = false;
      this.reimpresionfila = null;
    }
  }

  // ─── Anulación ───────────────────────────────────────────────────────────────

  abrirModalAnular(reporte: ReporteCFMensual): void {
    this.filaAnular = reporte;
    this.motivoAnulacion = '';
    this.invalidMotivo = false;
  }

  cerrarModalAnular(): void {
    this.filaAnular = null;
  }

  async confirmarAnulacion(): Promise<void> {
    this.invalidMotivo = !this.motivoAnulacion.trim();
    if (this.invalidMotivo || !this.filaAnular) return;

    this.anulando = true;
    try {
      await firstValueFrom(this.svc.anular(this.filaAnular.codigo_reporte, this.motivoAnulacion));
      this.toast('Reporte anulado correctamente.', true);
      this.cerrarModalAnular();
      await this.cargar();
    } catch (err: any) {
      this.toast(err?.error?.message ?? 'Error al anular.', false);
    } finally {
      this.anulando = false;
    }
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────────

  private toast(msg: string, ok: boolean): void {
    this.alertMsg = msg;
    this.alertOk = ok;
    setTimeout(() => { this.alertMsg = ''; }, 6000);
  }

  esReimprimiendo(reporte: ReporteCFMensual): boolean {
    return this.reimprimiendo && this.reimpresionfila?.codigo_reporte === reporte.codigo_reporte;
  }
}
