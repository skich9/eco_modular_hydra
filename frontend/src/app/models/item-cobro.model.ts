export interface ItemCobro {
	id_item: number;
	codigo_producto_impuesto?: number;
	codigo_producto_interno: string;
	unidad_medida: number;
	nombre_servicio: string;
	nro_creditos: number;
	costo?: number;
	facturado: boolean;
	actividad_economica: string;
	descripcion?: string;
	tipo_item: string;
	estado: boolean;
	id_parametro_economico: number;
	created_at?: string;
	updated_at?: string;
}
