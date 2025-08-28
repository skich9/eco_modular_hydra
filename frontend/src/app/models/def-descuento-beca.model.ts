export interface DefDescuentoBeca {
	cod_beca: number;
	nombre_beca: string;
	descripcion?: string | null;
	monto: number; // Si porcentaje=true, este valor representa el porcentaje (0-100). Si es false, es monto fijo
	porcentaje: boolean; // true = porcentaje, false = monto fijo
	estado: boolean;
	created_at?: string;
	updated_at?: string;
}
