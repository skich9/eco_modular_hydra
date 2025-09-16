export interface Materia {
	sigla_materia: string;
	cod_pensum: string;
	nombre_materia: string;
	nombre_material_oficial: string;
	estado: boolean;
	orden: number;
	descripcion?: string;
	nro_creditos: number;
	// Costo de la materia para la gestión actual (merge desde endpoint costo-materia)
	monto_materia?: number;
	pensum?: Pensum;
	created_at?: string;
	updated_at?: string;
}

export interface Pensum {
	cod_pensum: string;
	codigo_carrera: string;
	nombre: string;
	descripcion?: string;
	cantidad_semestres?: number;
	orden?: number;
	nivel?: string;
	estado?: boolean;
}

// ParametroEconomico eliminado de Materia: ya no se usa asociación directa
