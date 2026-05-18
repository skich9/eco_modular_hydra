import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

// ─── Interfaces ───────────────────────────────────────────────────────────────

export interface CajaActividad {
  id_caja_actividad: number;
  nombre_caja: string;
  prefijo: string;
  descripcion: string | null;
}

export interface MovimientoCF {
  tipo: 'ingreso' | 'egreso' | 'otro_ingreso';
  correlativo: string;
  fecha: string;
  descripcion: string;
  ingreso: number;
  egreso: number;
  saldo: number;
}

export interface DatosMovimientos {
  saldo_anterior: number;
  movimientos: MovimientoCF[];
  total_ingresos: number;
  total_egresos: number;
  saldo_final: number;
}

export interface ReporteCFMensual {
  codigo_reporte: number;
  cod_documento: string;
  fecha_inicio: string;
  fecha_fin: string;
  fecha_impresion: string;
  monto: number;
  nombre_usuario: string;
  anulado: boolean;
  motivo_anulacion: string | null;
  id_caja_actividad: number;
  nombre_caja: string | null;
}

export interface VerificarResponse {
  existe: boolean;
  anulado: boolean;
  reporte: ReporteCFMensual | null;
}

// ─── Servicio ─────────────────────────────────────────────────────────────────

@Injectable({ providedIn: 'root' })
export class ReporteCajaFuerteService {

  private readonly base = `${environment.apiUrl}/economico/reporte-caja-fuerte`;

  constructor(private http: HttpClient) {}

  initialData(): Observable<{ cajas: CajaActividad[] }> {
    return this.http.get<{ cajas: CajaActividad[] }>(`${this.base}/initial`);
  }

  getMovimientos(fechaIni: string, idCaja: number): Observable<DatosMovimientos> {
    return this.http.post<DatosMovimientos>(`${this.base}/movimientos`, {
      fecha_ini: fechaIni,
      id_caja_actividad: idCaja,
    });
  }

  verificar(fechaIni: string, idCaja: number): Observable<VerificarResponse> {
    return this.http.post<VerificarResponse>(`${this.base}/verificar`, {
      fecha_ini: fechaIni,
      id_caja_actividad: idCaja,
    });
  }

  imprimir(fechaIni: string, idCaja: number, monto: number, reimpreso = false): Observable<{ reporte: ReporteCFMensual; url: string }> {
    return this.http.post<{ reporte: ReporteCFMensual; url: string }>(`${this.base}/imprimir`, {
      fecha_ini: fechaIni,
      id_caja_actividad: idCaja,
      monto,
      reimpreso,
    });
  }

  listar(): Observable<ReporteCFMensual[]> {
    return this.http.get<ReporteCFMensual[]>(`${this.base}/listar`);
  }

  anular(codigoReporte: number, motivoAnulacion: string): Observable<any> {
    return this.http.post<any>(`${this.base}/anular`, {
      codigo_reporte: codigoReporte,
      motivo_anulacion: motivoAnulacion,
    });
  }
}
