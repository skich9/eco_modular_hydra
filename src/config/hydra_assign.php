<?php

return [
	// Mapeo de tipo_inscripcion (SGA) -> lista de tipo_costo (costo_semestral) en orden de preferencia
	// Defaults alineados a tu BD: 'costo_mensual' y 'materia'. Puedes sobreescribir vía .env:
	// HYDRA_INSCR_TIPO_COSTO_NORMAL="costo_mensual,costo_semestral"
	// HYDRA_INSCR_TIPO_COSTO_ARRASTRE="materia"
	'tipo_costo_map' => [
		'NORMAL' => array_filter(array_map('trim', explode(',', env('HYDRA_INSCR_TIPO_COSTO_NORMAL', 'costo_mensual,costo_semestral')))),
		'ARRASTRE' => array_filter(array_map('trim', explode(',', env('HYDRA_INSCR_TIPO_COSTO_ARRASTRE', 'materia')))),
	],

	// Normalización de turno, a partir del último carácter del cod_curso: M/T/N
	// Puedes sobreescribir vía .env:
	// HYDRA_INSCR_TURNO_M="MANANA,MAÑANA,M"
	// HYDRA_INSCR_TURNO_T="TARDE,T"
	// HYDRA_INSCR_TURNO_N="NOCHE,N"
	'turno_map' => [
		'M' => array_filter(array_map('trim', explode(',', env('HYDRA_INSCR_TURNO_M', 'MANANA,MAÑANA,M')))),
		'T' => array_filter(array_map('trim', explode(',', env('HYDRA_INSCR_TURNO_T', 'TARDE,T')))),
		'N' => array_filter(array_map('trim', explode(',', env('HYDRA_INSCR_TURNO_N', 'NOCHE,N')))),
	],

	// Permitir fallback opcional: si no hay match por tipo_costo, buscar ignorando tipo_costo
	// HYDRA_ASSIGN_FALLBACK_ANY_TIPO=true|false
	'fallback_any_tipo' => env('HYDRA_ASSIGN_FALLBACK_ANY_TIPO', false),

	// Para NORMAL, excluir ciertos tipo_costo cuando se aplica fallback (evitar tomar 'materia')
	// HYDRA_FORBIDDEN_TIPO_COSTO_NORMAL="materia"
	'forbidden_when_normal' => array_filter(array_map('trim', explode(',', env('HYDRA_FORBIDDEN_TIPO_COSTO_NORMAL', 'materia')))),

	// Defaults para generación de cuotas cuando no hay plantilla en tabla 'cuotas'
	'cuotas_defaults' => [
		'count' => (int) env('HYDRA_CUOTAS_DEFAULT_COUNT', 5),
		'first_due_day' => (int) env('HYDRA_CUOTAS_DEFAULT_DAY', 15),
		'interval_days' => (int) env('HYDRA_CUOTAS_INTERVAL_DAYS', 30),
		'use_calendar_month' => (bool) env('HYDRA_CUOTAS_USE_CALENDAR_MONTH', true),
	],

	// Mapeo de tipo_costo (costo_semestral) -> lista de tipos de plantilla válidos en 'cuotas'
	// Permite múltiples alias separados por coma desde .env
	'cuotas_tipo_map' => [
		'costo_mensual' => array_filter(array_map('trim', explode(',', env('HYDRA_CUOTAS_TIPO_MAP_COSTO_MENSUAL', 'costo_mensual')))),
		'materia' => array_filter(array_map('trim', explode(',', env('HYDRA_CUOTAS_TIPO_MAP_MATERIA', 'arrastre,materia,1 materia')))),
	],
];
