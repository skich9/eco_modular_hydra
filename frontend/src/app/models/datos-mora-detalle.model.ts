export interface DatosMoraDetalle {
	id_datos_mora_detalle?: number;
	id_datos_mora: number;
	cuota: number | null;
	semestre: string;
	cod_pensum?: string;
	monto: number | null;
	fecha_inicio: string | null;
	fecha_fin: string | null;
	activo: boolean;
	created_at?: string;
	updated_at?: string;
	// Relaciones
	datos_mora?: {
		id_datos_mora: number;
		gestion: string;
		tipo_calculo?: string;
		monto?: number;
		activo?: boolean;
	};
	pensum?: {
		cod_pensum: string;
		nombre?: string;
		descripcion?: string;
		carrera?: {
			codigo_carrera: string;
			nombre?: string;
			descripcion?: string;
		};
	};
}
