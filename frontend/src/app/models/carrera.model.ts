export interface Carrera {
	codigo_carrera: string;
	nombre: string;
	descripcion?: string;
	prefijo_matricula?: string;
	callback?: string;
	estado: boolean;
	created_at?: string;
	updated_at?: string;
}
