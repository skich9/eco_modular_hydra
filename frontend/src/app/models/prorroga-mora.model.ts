export interface ProrrogaMora {
	id_prorroga_mora?: number;
	id_usuario: number;
	cod_ceta: number;
	id_asignacion_costo: number;
	fecha_inicio_prorroga: string;
	fecha_fin_prorroga: string;
	activo: boolean;
	motivo?: string;
	created_at?: string;
	updated_at?: string;

	// Relaciones
	usuario?: {
		id_usuario: number;
		nombre?: string;
		email?: string;
	};
	estudiante?: {
		cod_ceta: number;
		nombre?: string;
		apellido_paterno?: string;
		apellido_materno?: string;
		ci?: string;
	};
	asignacionCosto?: {
		id_asignacion_costo: number;
		numero_cuota?: number;
		monto?: number;
		estado_pago?: string;
		cod_pensum?: string;
		gestion?: string;
	};
}
