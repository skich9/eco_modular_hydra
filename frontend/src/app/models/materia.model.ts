export interface Materia {
	sigla_materia: string;
	cod_pensum: string;
	nombre_materia: string;
	nombre_material_oficial: string;
	estado: boolean;
	orden: number;
	descripcion?: string;
	id_parametro_economico: number;
	nro_creditos: number;
	parametroEconomico?: ParametroEconomico;
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

export interface ParametroEconomico {
	id_parametro_economico: number;
	nombre: string;
	tipo: string;
	valor: number;
	descripcion?: string;
	estado: boolean;
}
