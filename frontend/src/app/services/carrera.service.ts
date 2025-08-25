import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { Carrera } from '../models/carrera.model';
import { Pensum } from '../models/materia.model';

@Injectable({ providedIn: 'root' })
export class CarreraService {
	private apiUrl = `${environment.apiUrl}`;

	constructor(private http: HttpClient) {}

	// Listar todas las carreras
	getAll(): Observable<{ success: boolean; data: Carrera[] }> {
		return this.http.get<{ success: boolean; data: Carrera[] }>(`${this.apiUrl}/carreras`);
	}

	// Listar pensums por carrera
	getPensums(codigoCarrera: string): Observable<{ success: boolean; data: Pensum[] }> {
		return this.http.get<{ success: boolean; data: Pensum[] }>(`${this.apiUrl}/carreras/${codigoCarrera}/pensums`);
	}
}
