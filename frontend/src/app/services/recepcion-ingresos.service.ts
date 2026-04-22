import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

// ─── Interfaces de dominio ────────────────────────────────────────────────────

export interface CarreraRecepcion {
  codigo: string;
  nombre: string;
}

export interface Tesorero {
  id_usuario: string;
  nickname: string;
  nombre: string;
  label: string;
}

export interface UsuarioActivo {
  id_usuario: string;
  nickname: string;
  nombre: string;
  label: string;
}

export interface ActividadEconomica {
  id: number;
  descripcion: string;
}

export interface RecepcionIngresoDetalle {
  id?: number;
  recepcion_ingreso_id?: number;
  usuario_libro?: string;
  cod_libro_diario?: string;
  fecha_inicial_libros?: string | null;
  fecha_final_libros: string;
  total_deposito?: number;
  total_traspaso?: number;
  total_recibos?: number;
  total_facturas?: number;
  total_entregado?: number;
  faltante_sobrante?: number | null;
}

export interface RecepcionIngreso {
  id?: number;
  codigo_carrera: string;
  fecha_recepcion: string;
  fecha_registro?: string;
  usuario_entregue1: string;
  usuario_recibi1: string;
  usuario_entregue2?: string | null;
  usuario_recibi2?: string | null;
  usuario_registro?: string;
  cod_documento?: string;
  num_documento?: number;
  observacion?: string | null;
  monto_total?: number;
  id_actividad_economica?: number | null;
  es_ingreso_libro_diario?: boolean;
  anulado?: boolean;
  motivo_anulacion?: string | null;
  detalles?: RecepcionIngresoDetalle[];
}

export interface RecepcionFiltros {
  codigo_carrera?: string;
  fecha_desde?: string;
  fecha_hasta?: string;
  id_actividad_economica?: number;
  anulado?: boolean;
  per_page?: number;
}

export interface ReporteFiltros {
  codigo_carrera: string;
  fecha_desde: string;
  fecha_hasta: string;
  id_actividad_economica?: number;
  usuario_entregue1: string;
  usuario_recibi1: string;
  usuario_entregue2?: string;
  usuario_recibi2?: string;
}

export interface InitialData {
  carreras: CarreraRecepcion[];
  actividades: ActividadEconomica[];
  tesoreros: Tesorero[];
  usuarios_activos: UsuarioActivo[];
  usuarios_libros: { usuario: string }[];
}

// ─── Servicio ─────────────────────────────────────────────────────────────────

@Injectable({ providedIn: 'root' })
export class RecepcionIngresosService {

  private readonly base = `${environment.apiUrl}/economico/recepcion-ingresos`;

  constructor(private http: HttpClient) {}

  /** Carga catálogos iniciales para el formulario */
  initialData(): Observable<{ success: boolean; data: InitialData }> {
    return this.http.get<{ success: boolean; data: InitialData }>(`${this.base}/initial`);
  }

  /** Obtiene el siguiente número y código de documento para carrera + fecha */
  siguienteNumDocumento(carrera: string, fecha: string): Observable<any> {
    const params = new HttpParams()
      .set('carrera', carrera)
      .set('fecha', fecha);
    return this.http.get<any>(`${this.base}/siguiente-num-documento`, { params });
  }

  /** Lista recepciones con filtros opcionales */
  listar(filtros: RecepcionFiltros = {}): Observable<any> {
    let params = new HttpParams();
    Object.entries(filtros).forEach(([key, val]) => {
      if (val !== undefined && val !== null && val !== '') {
        params = params.set(key, String(val));
      }
    });
    return this.http.get<any>(`${this.base}/listar`, { params });
  }

  /** Registra una nueva recepción de ingresos */
  registrar(body: RecepcionIngreso): Observable<any> {
    return this.http.post<any>(`${this.base}/registrar`, body);
  }

  /** Genera los datos del reporte de ingresos */
  generarReporte(filtros: ReporteFiltros): Observable<any> {
    return this.http.post<any>(`${this.base}/generar-reporte`, filtros);
  }

  /** Recupera una recepción con sus detalles */
  show(id: number): Observable<any> {
    return this.http.get<any>(`${this.base}/${id}`);
  }

  /** Anula una recepción */
  anular(id: number, motivo: string): Observable<any> {
    return this.http.post<any>(`${this.base}/${id}/anular`, { motivo });
  }
}
