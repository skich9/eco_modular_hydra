export interface Descuento {
	id_descuentos: number;
	cod_ceta: number;
	cod_pensum: string;
	cod_inscrip: number;
	id_usuario: number;
	nombre: string;
	observaciones?: string | null;
	porcentaje: number;
	tipo?: string | null;
	estado: boolean;
	created_at?: string;
	updated_at?: string;
}
