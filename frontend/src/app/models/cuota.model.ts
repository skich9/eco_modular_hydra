export interface Cuota {
	id_cuota: number;
	nombre: string;
	descripcion?: string;
	monto: number;
	turno?: string;
	estado?: string;
	tipo?: string;
	created_at?: string;
	updated_at?: string;
}
