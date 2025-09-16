import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../environments/environment';

export interface SinActividad {
  codigo_caeb: string;
  descripcion: string;
}

@Injectable({ providedIn: 'root' })
export class SinActividadesService {
  private apiUrl = `${environment.apiUrl}/sin-actividades`;

  constructor(private http: HttpClient) {}

  search(q: string = '', limit: number = 50): Observable<{ success: boolean; data: SinActividad[] }> {
    let params = new HttpParams();
    if (q && q.trim().length > 0) params = params.set('q', q.trim());
    if (limit) params = params.set('limit', String(limit));

    return this.http.get<any>(this.apiUrl, { params }).pipe(
      map((res: any) => ({ success: !!res?.success, data: (res?.data || []) as SinActividad[] }))
    );
  }

  listAll(limit: number = 50): Observable<SinActividad[]> {
    return this.search('', limit).pipe(map((r) => r.data));
  }
}
