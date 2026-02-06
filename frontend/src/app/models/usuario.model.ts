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
	created_at?: string;
	updated_at?: string;
}

export interface Rol {
	id_rol: number;
	nombre: string;
	descripcion: string;
	estado: boolean;
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
