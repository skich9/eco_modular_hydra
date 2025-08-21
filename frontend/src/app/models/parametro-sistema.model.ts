export interface ParametroSistema {
	id: number;
	nombre: string;
	tipo: string; // Campo principal para tipo
	tipo_parametro?: string; // Campo alternativo para compatibilidad
	valor: string;
	descripcion?: string;
	estado: boolean;
	created_at?: string;
	updated_at?: string;
}
