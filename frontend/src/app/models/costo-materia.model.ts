export interface CostoMateria {
	id_costo_materia?: number | string;
	cod_pensum: string;
	sigla_materia: string;
	gestion: string;
	valor_credito: number;
	monto_materia: number;
	turno?: string;
	id_usuario?: number;
	created_at?: string;
	updated_at?: string;
}
