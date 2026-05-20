import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface CajaActividad {
  id_caja_actividad: number;
  nombre_caja: string;
  prefijo: string;
}

export interface EgresoCajaFuerte {
  codigo_egreso: number;
  correlativo: string;
  fecha_egreso: string;
  monto: number;
  descripcion: string;
  observacion: string | null;
  usuario: number;
  usuario_modifica: number | null;
  anular: boolean;
  motivo_anulacion: string | null;
  id_caja_actividad: number;
  caja?: CajaActividad;
}

export interface InitialDataEgreso {
  cajas: CajaActividad[];
  egresos: EgresoCajaFuerte[];
}

export interface RegistrarEgresoPayload {
  correlativo: string;
  id_caja_actividad: number;
  fecha_egreso: string;
  monto: number;
  descripcion: string;
  observacion?: string | null;
}

export interface EditarEgresoPayload extends RegistrarEgresoPayload {
  codigo_egreso: number;
}

export interface EliminarEgresoPayload {
  codigo_egreso: number;
  motivo_anulacion: string;
}

@Injectable({ providedIn: 'root' })
export class EgresoCajaFuerteService {
  private readonly base = `${environment.apiUrl}/economico/caja-fuerte`;

  constructor(private readonly http: HttpClient) {}

  initialData(): Observable<{ success: boolean; data: InitialDataEgreso }> {
    return this.http.get<{ success: boolean; data: InitialDataEgreso }>(`${this.base}/initial`);
  }

  registrar(payload: RegistrarEgresoPayload): Observable<{ success: boolean; message: string; data: EgresoCajaFuerte }> {
    return this.http.post<{ success: boolean; message: string; data: EgresoCajaFuerte }>(`${this.base}/registrar`, payload);
  }

  editar(payload: EditarEgresoPayload): Observable<{ success: boolean; message: string; data: EgresoCajaFuerte }> {
    return this.http.post<{ success: boolean; message: string; data: EgresoCajaFuerte }>(`${this.base}/editar`, payload);
  }

  eliminar(payload: EliminarEgresoPayload): Observable<{ success: boolean; message: string }> {
    return this.http.post<{ success: boolean; message: string }>(`${this.base}/eliminar`, payload);
  }
}
