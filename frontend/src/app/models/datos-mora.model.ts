import { DatosMoraDetalle } from './datos-mora-detalle.model';

export interface DatosMora {
	id_datos_mora?: number;
	gestion: string;
	tipo_calculo: 'PORCENTAJE' | 'MONTO_FIJO' | 'AMBOS';
	monto: number | null;
	activo: boolean;
	created_at?: string;
	updated_at?: string;
	detalles?: DatosMoraDetalle[];
}
