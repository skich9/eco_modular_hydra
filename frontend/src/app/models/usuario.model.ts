export interface Usuario {
	id_usuario: number;
	nickname: string;
	nombre: string;
	ap_paterno: string;
	ap_materno: string;
	ci: string;
	estado: boolean;
	id_rol: number;
	rol?: Rol;
	nombre_completo?: string;
	funciones?: UsuarioFuncion[];
	created_at?: string;
	updated_at?: string;
}

export interface UsuarioFuncion {
	id_funcion: number;
	codigo: string;
	nombre: string;
	descripcion?: string;
	modulo: string;
	icono?: string;
	fecha_ini: string;
	fecha_fin?: string | null;
	observaciones?: string;
}

export interface Rol {
	id_rol: number;
	nombre: string;
	descripcion: string;
	estado: boolean;
	funciones_count?: number; // Contador de funciones asignadas
	usuarios_count?: number; // Contador de usuarios con este rol
}

export interface AuthResponse {
	success: boolean;
	message?: string;
	token?: string;
	expires_at?: string;
	usuario?: Usuario;
}

export interface LoginRequest {
	nickname: string;
	contrasenia: string;
}
