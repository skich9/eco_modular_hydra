export interface DefDescuento {
	cod_descuento: number;
	nombre_descuento: string;
	descripcion?: string | null;
	monto: number; // Si porcentaje=true, este valor representa el porcentaje (0-100). Si es false, es monto fijo
	porcentaje: boolean; // true = porcentaje, false = monto fijo
	estado: boolean;
	d_i?: boolean; // true = descuento institucional (solo permite recibo, no factura)
	created_at?: string;
	updated_at?: string;
}
