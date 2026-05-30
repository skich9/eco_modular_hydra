import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

export interface SgaSyncError {
  id: number;
  documento: string;
  estudiante: string;
  concepto: string;
  mensaje: string;
  intentos: number;
  fecha: string;
}

@Injectable({
  providedIn: 'root'
})
export class SgaPushService {
  private apiUrl = `${environment.apiUrl}/sga-push`;

  constructor(private http: HttpClient) { }

  /**
   * Obtiene la lista de cobros pendientes de sincronización
   */
  getPendingSyncs(): Observable<any> {
    return this.http.get(`${this.apiUrl}/pending`);
  }

  /**
   * Reintenta la sincronización de un cobro específico
   */
  retrySync(id: number): Observable<any> {
    return this.http.post(`${this.apiUrl}/retry/${id}`, {});
  }

  /**
   * Reintenta todas las sincronizaciones pendientes
   */
  retryAll(): Observable<any> {
    return this.http.post(`${this.apiUrl}/retry-all`, {});
  }

  /**
   * Obtiene el detalle estructurado de pagos para un cobro específico
   */
  getSyncDetail(id: number): Observable<any> {
    return this.http.get(`${this.apiUrl}/${id}`);
  }
}
