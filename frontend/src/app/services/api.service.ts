import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

@Injectable({
	providedIn: 'root'
})
export class ApiService {
	// URL base para la API de Laravel
	private apiUrl = 'http://localhost:8080/api';

	constructor(private http: HttpClient) { }

	/**
	 * Método para realizar prueba de conexión
	 */
	testApi(): Observable<any> {
		return this.http.get(`${this.apiUrl}/test`);
	}

	/**
	 * Método para verificar estado de la API
	 */
	healthCheck(): Observable<any> {
		return this.http.get(`${this.apiUrl}/health`);
	}
}
