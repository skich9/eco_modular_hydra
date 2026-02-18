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
}
