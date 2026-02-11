export interface Funcion {
	id_funcion: number;
	codigo: string;
	nombre: string;
	descripcion?: string;
	modulo: string;
	activo: boolean;
	created_at?: string;
	updated_at?: string;
}

export interface UsuarioFuncion {
	id_funcion: number;
	codigo: string;
	nombre: string;
	descripcion?: string;
	modulo: string;
	fecha_ini: string;
	fecha_fin?: string | null;
	observaciones?: string;
	activo?: boolean;
}

export interface AsignarFuncionRequest {
	id_funcion: number;
	fecha_ini?: string;
	fecha_fin?: string | null;
	observaciones?: string;
}

export interface CopiarFuncionesRolRequest {
	id_rol: number;
	replace?: boolean;
}

export interface FuncionesAgrupadasPorModulo {
	[modulo: string]: Funcion[];
}

export interface ApiResponse<T> {
	success: boolean;
	data?: T;
	message?: string;
	errors?: any;
}
