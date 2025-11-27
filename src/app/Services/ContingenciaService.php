<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Siat\RecepcionPaqueteService;
use App\Services\Siat\ValidacionPaqueteService;
use App\Services\Siat\RegistroEventoService;

class ContingenciaService
{
	/**
	 * Obtiene lista de facturas en contingencia pendientes de regularizar
	 * 
	 * @param int|null $sucursal
	 * @param string|null $puntoVenta
	 * @return array
	 */
	public function listarContingencias($sucursal = null, $puntoVenta = null)
	{
		$query = DB::table('factura')
			->where('codigo_tipo_emision', 2) // Contingencia
			->whereIn('estado', ['CONTINGENCIA', 'PENDIENTE'])
			->orderBy('fecha_emision', 'asc');

		if ($sucursal !== null) {
			$query->where('codigo_sucursal', $sucursal);
		}
		if ($puntoVenta !== null) {
			$query->where('codigo_punto_venta', $puntoVenta);
		}

		$facturas = $query->get();

		// Calcular tiempo restante para cada factura
		$resultado = [];
		foreach ($facturas as $factura) {
			$esManual = ($factura->tipo === 'M');
			$limiteHoras = $esManual ? 72 : 48;
			
			$fechaEmision = strtotime($factura->fecha_emision);
			$ahora = time();
			$horasTranscurridas = ($ahora - $fechaEmision) / 3600;
			$horasRestantes = $limiteHoras - $horasTranscurridas;
			
			$resultado[] = [
				'nro_factura' => $factura->nro_factura,
				'anio' => $factura->anio,
				'tipo' => $factura->tipo,
				'fecha_emision' => $factura->fecha_emision,
				'monto_total' => $factura->monto_total,
				'cliente' => isset($factura->cliente) ? $factura->cliente : null,
				'codigo_punto_venta' => $factura->codigo_punto_venta,
				'codigo_sucursal' => $factura->codigo_sucursal,
				'codigo_cufd' => $factura->codigo_cufd,
				'cafc' => isset($factura->cafc) ? $factura->cafc : (isset($factura->codigo_cafc) ? $factura->codigo_cafc : null),
				'codigo_evento' => $factura->codigo_evento,
				'descripcion_evento' => $factura->descripcion_evento,
				'estado' => $factura->estado,
				'horas_restantes' => round($horasRestantes, 2),
				'fuera_de_plazo' => $horasRestantes <= 0,
				'es_manual' => $esManual,
				'limite_horas' => $limiteHoras
			];
		}

		return $resultado;
	}

	/**
	 * Agrupa facturas por CUFD y evento para envío en paquetes
	 * 
	 * @param array $facturas Array de nro_factura/anio
	 * @return array
	 */
	public function agruparFacturasPorPaquete($facturas)
	{
		$paquetes = [];

		foreach ($facturas as $item) {
			$factura = DB::table('factura')
				->where('nro_factura', $item['nro_factura'])
				->where('anio', $item['anio'])
				->first();

			if (!$factura) {
				continue;
			}

			// Clave del paquete: CUFD + Evento (si existe)
			$claveEvento = $factura->codigo_evento ? $factura->codigo_evento : 'sin_evento';
			$clavePaquete = $factura->codigo_cufd . '_' . $claveEvento;

			if (!isset($paquetes[$clavePaquete])) {
				$paquetes[$clavePaquete] = [
					'cufd' => $factura->codigo_cufd,
					'cafc' => $factura->cafc,
					'codigo_evento' => $factura->codigo_evento,
					'descripcion_evento' => $factura->descripcion_evento,
					'facturas' => []
				];
			}

			$paquetes[$clavePaquete]['facturas'][] = $factura;
		}

		return $paquetes;
	}

	/**
	 * Regulariza un paquete de facturas en contingencia
	 * 
	 * @param array $paquete
	 * @return array
	 */
	public function regularizarPaquete($paquete)
	{
		try {
			// 1. Generar XML del paquete
			$xml = $this->generarXmlPaquete($paquete['facturas']);
			
			// 2. Comprimir y codificar
			$xmlComprimido = gzencode($xml);
			$archivo = base64_encode($xmlComprimido);
			
			// 3. Calcular hash
			$hashArchivo = hash('sha256', $xmlComprimido);
			
			// 4. Obtener CUIS vigente
			$cuis = $this->obtenerCuisVigente();
			
			// 5. Obtener punto de venta y sucursal de la primera factura
			$primeraFactura = $paquete['facturas'][0];
			$puntoVenta = isset($primeraFactura->codigo_punto_venta) ? $primeraFactura->codigo_punto_venta : 0;
			$sucursal = isset($primeraFactura->codigo_sucursal) ? $primeraFactura->codigo_sucursal : null;
			
			// 5.1. Registrar evento significativo si es necesario
			$codigoRecepcionEvento = null;
			if ($paquete['codigo_evento'] && $paquete['codigo_evento'] > 0) {
				// Verificar si ya existe un evento registrado para este CUFD y punto de venta
				$eventoExistente = DB::table('sin_evento_significativo')
					->where('codigo_punto_venta', $puntoVenta)
					->where('codigo_sucursal', $sucursal)
					->orderBy('id_evento', 'desc')
					->first();
				
				if ($eventoExistente) {
					// Usar el evento existente
					$codigoRecepcionEvento = $eventoExistente->codigo_recepcion;
					Log::info('ContingenciaService.eventoExistente', [
						'codigo_recepcion' => $codigoRecepcionEvento
					]);
				} else {
					// Registrar nuevo evento en el SIN
					$registroEventoService = new RegistroEventoService();
					
					// Obtener CUFD vigente ANTES de registrar el evento (según documentación SIN)
					// "Obtener un nuevo CUFD antes de registrar el evento significativo y enviar los paquetes"
					$cufdVigente = $this->obtenerCufdVigente();
					
					// Obtener fechas de inicio y fin del evento (basadas en las facturas)
					// Las fechas deben corresponder al período real de la contingencia
					$fechas = $this->obtenerFechasEvento($paquete['facturas']);
					
					try {
						$respuestaEvento = $registroEventoService->registrarEvento(
							$cuis,
							$cufdVigente, // CUFD vigente actual (obtenido antes del registro)
							$cufdVigente, // cufdEvento = mismo CUFD vigente (no el antiguo de las facturas)
							$paquete['codigo_evento'],
							$paquete['descripcion_evento'],
							$fechas['inicio'],
							$fechas['fin'],
							$puntoVenta,
							$sucursal
						);
						
						if (isset($respuestaEvento['RespuestaListaEventos']['codigoRecepcionEventoSignificativo'])) {
							$codigoRecepcionEvento = $respuestaEvento['RespuestaListaEventos']['codigoRecepcionEventoSignificativo'];
							
							// Guardar en la base de datos
							DB::table('sin_evento_significativo')->insert([
								'codigo_recepcion' => $codigoRecepcionEvento,
								'fecha_inicio' => now()->timezone('America/La_Paz')->parse($fechas['inicio']),
								'fecha_fin' => now()->timezone('America/La_Paz')->parse($fechas['fin']),
								'codigo_evento' => $paquete['codigo_evento'],
								'codigo_sucursal' => $sucursal,
								'codigo_punto_venta' => $puntoVenta
							]);
							
							Log::info('ContingenciaService.eventoRegistrado', [
								'codigo_recepcion' => $codigoRecepcionEvento
							]);
						}
					} catch (\Throwable $e) {
						Log::warning('ContingenciaService.errorRegistroEvento', [
							'error' => $e->getMessage()
						]);
						// Continuar sin evento (el SIN podría aceptarlo)
					}
				}
			}
			
			// 6. Enviar paquete al SIN
			$recepcionService = new RecepcionPaqueteService();
			$codigoDocumentoSector = (int) config('sin.cod_doc_sector', 11);
			$tipoFacturaDocumento = (int) config('sin.tipo_factura', 1);
			
			// Usar la fecha actual en zona horaria de Bolivia (UTC-4)
			// El SIN valida que la fecha esté dentro de 300 segundos (5 minutos) de su hora
			$fechaEnvio = now()->timezone('America/La_Paz')->format('Y-m-d\TH:i:s.v');
			
			$respuesta = $recepcionService->enviarPaquete(
				$cuis,
				$paquete['cufd'],
				$codigoDocumentoSector, // Desde configuración (11 = Educación)
				2, // codigoEmision (2 = Contingencia)
				$tipoFacturaDocumento, // Desde configuración (1 = Factura con derecho a crédito fiscal)
				$archivo,
				$fechaEnvio,
				$hashArchivo,
				count($paquete['facturas']),
				$paquete['cafc'],
				$codigoRecepcionEvento, // Código de recepción del evento (no el código del tipo)
				$puntoVenta,
				$sucursal
			);

			// 6. Verificar respuesta
			if (!isset($respuesta['RespuestaServicioFacturacion'])) {
				throw new \RuntimeException('Respuesta inválida del SIN');
			}

			$resp = $respuesta['RespuestaServicioFacturacion'];
			
			// Preparar lista de números de factura para logging
			$numerosFacturas = array_map(function($f) {
				return $f->anio . '_' . $f->nro_factura;
			}, $paquete['facturas']);
			$stringFacturas = implode(', ', $numerosFacturas);
			
			// Verificar si fue rechazada
			if (isset($resp['codigoDescripcion']) && $resp['codigoDescripcion'] === 'RECHAZADA') {
				// Registrar en sin_recepcion_paquete_factura
				DB::table('sin_recepcion_paquete_factura')->insert([
					'descripcion' => $resp['codigoDescripcion'],
					'estado' => isset($resp['codigoEstado']) ? $resp['codigoEstado'] : null,
					'codigo_recepcion' => null,
					'facturas' => $stringFacturas,
					'nombre_salida' => null,
					'mensajes_list' => isset($resp['mensajesList']) ? json_encode($resp['mensajesList']) : null,
					'fecha_registro' => now()
				]);
				
				$mensaje = isset($resp['mensajesList']['descripcion']) ? $resp['mensajesList']['descripcion'] : 'Error desconocido';
				throw new \RuntimeException('Paquete RECHAZADO: ' . $mensaje);
			}
			
			if (isset($resp['transaccion']) && $resp['transaccion'] === false) {
				$mensaje = isset($resp['mensajesList']['descripcion']) ? $resp['mensajesList']['descripcion'] : 'Error desconocido';
				throw new \RuntimeException($mensaje);
			}

			$codigoRecepcion = isset($resp['codigoRecepcion']) ? $resp['codigoRecepcion'] : null;
			
			if (!$codigoRecepcion) {
				throw new \RuntimeException('No se recibió código de recepción');
			}
			
			// Registrar recepción exitosa en sin_recepcion_paquete_factura
			DB::table('sin_recepcion_paquete_factura')->insert([
				'descripcion' => isset($resp['codigoDescripcion']) ? $resp['codigoDescripcion'] : 'PENDIENTE',
				'estado' => isset($resp['codigoEstado']) ? $resp['codigoEstado'] : null,
				'codigo_recepcion' => $codigoRecepcion,
				'facturas' => $stringFacturas,
				'nombre_salida' => null,
				'mensajes_list' => null,
				'fecha_registro' => now()
			]);

			// 7. Guardar código de recepción en las facturas
			foreach ($paquete['facturas'] as $factura) {
				DB::table('factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'codigo_recepcion_paquete' => $codigoRecepcion,
						'fecha_envio' => now()
					]);
			}

			// 8. Validar paquete
			sleep(2); // Esperar 2 segundos antes de validar
			
			$validacionService = new ValidacionPaqueteService();
			$validacion = $validacionService->validarPaquete(
				$cuis,
				$paquete['cufd'],
				$codigoDocumentoSector, // Usar el mismo valor que en envío
				2, // codigoEmision (2 = Contingencia)
				$tipoFacturaDocumento, // Usar el mismo valor que en envío
				$codigoRecepcion,
				$puntoVenta,
				$sucursal
			);

			// 9. Procesar resultados de validación
			$this->procesarValidacion($validacion, $paquete['facturas']);

			// 10. Registrar intento exitoso en regulacion_factura
			$this->registrarIntentoRegularizacion(
				$paquete['facturas'],
				$paquete['cafc'],
				$paquete['codigo_evento'],
				true, // transaccion exitosa
				$codigoRecepcion,
				null,
				false // no es manual
			);

			return [
				'success' => true,
				'codigo_recepcion' => $codigoRecepcion,
				'cantidad_facturas' => count($paquete['facturas']),
				'validacion' => $validacion
			];

		} catch (\Throwable $e) {
			Log::error('ContingenciaService.regularizarPaquete', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			// Registrar intento fallido en regulacion_factura
			$this->registrarIntentoRegularizacion(
				$paquete['facturas'],
				$paquete['cafc'],
				$paquete['codigo_evento'],
				false, // transaccion fallida
				null,
				$e->getMessage(),
				false // no es manual
			);

			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Genera XML del paquete de facturas
	 * 
	 * @param array $facturas
	 * @return string
	 */
	private function generarXmlPaquete($facturas)
	{
		// TODO: Implementar generación de XML según especificaciones del SIN
		// Por ahora retornamos un XML básico
		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<paqueteFacturas>';
		
		foreach ($facturas as $factura) {
			// Aquí iría la estructura completa de cada factura
			$xml .= '<factura>';
			$xml .= '<cuf>' . htmlspecialchars($factura->cuf) . '</cuf>';
			$xml .= '<numeroFactura>' . $factura->nro_factura . '</numeroFactura>';
			$xml .= '<montoTotal>' . $factura->monto_total . '</montoTotal>';
			// ... más campos según especificación
			$xml .= '</factura>';
		}
		
		$xml .= '</paqueteFacturas>';
		
		return $xml;
	}

	/**
	 * Procesa la validación del paquete y actualiza estados
	 * Maneja respuestas: VALIDADA, PENDIENTE, RECHAZADA, OBSERVADA
	 * 
	 * @param array $validacion
	 * @param array $facturas
	 */
	private function procesarValidacion($validacion, $facturas)
	{
		if (!isset($validacion['RespuestaServicioFacturacion'])) {
			return;
		}

		$resp = $validacion['RespuestaServicioFacturacion'];
		$codigoDescripcion = isset($resp['codigoDescripcion']) ? $resp['codigoDescripcion'] : null;

		Log::info('ContingenciaService.procesarValidacion', [
			'codigoDescripcion' => $codigoDescripcion,
			'cantidad_facturas' => count($facturas)
		]);

		// Caso 1: VALIDADA - Todas las facturas fueron aceptadas
		if ($codigoDescripcion === 'VALIDADA') {
			foreach ($facturas as $factura) {
				DB::table('factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => 'Enviado',
						'aceptado_impuestos' => true,
						'mensaje_sin' => 'Factura validada correctamente'
					]);
				
				// Actualizar regulacion_factura
				DB::table('regulacion_factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => 'COMPLETADO',
						'errores' => null
					]);
			}
			return;
		}

		// Caso 2: PENDIENTE - Todas las facturas quedan pendientes
		if ($codigoDescripcion === 'PENDIENTE') {
			foreach ($facturas as $factura) {
				DB::table('factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => 'PENDIENTE',
						'mensaje_sin' => 'Validación pendiente en el SIN'
					]);
			}
			return;
		}

		// Caso 3: RECHAZADA - Todas las facturas fueron rechazadas
		if ($codigoDescripcion === 'RECHAZADA') {
			$mensajeError = isset($resp['mensajesList']) ? json_encode($resp['mensajesList']) : 'Paquete rechazado';
			foreach ($facturas as $factura) {
				DB::table('factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => 'Rechazado',
						'mensaje_sin' => $mensajeError
					]);
				
				// Actualizar regulacion_factura
				DB::table('regulacion_factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => 'FALLIDO',
						'errores' => $mensajeError
					]);
			}
			return;
		}

		// Caso 4: OBSERVADA - Algunas facturas tienen errores, otras fueron aceptadas
		if ($codigoDescripcion === 'OBSERVADA') {
			$mensajesList = isset($resp['mensajesList']) ? $resp['mensajesList'] : [];
			
			// Si es un solo mensaje, convertir a array
			if (isset($mensajesList['descripcion'])) {
				$mensajesList = [$mensajesList];
			}

			// Crear mapa de errores por numeroArchivo
			$erroresPorArchivo = [];
			foreach ($mensajesList as $mensaje) {
				$numeroArchivo = isset($mensaje['numeroArchivo']) ? (int)$mensaje['numeroArchivo'] : null;
				$codigo = isset($mensaje['codigo']) ? $mensaje['codigo'] : null;
				$esAdvertencia = isset($mensaje['advertencia']) && $mensaje['advertencia'] === 'true';
				
				// Ignorar código 1000 (factura ya registrada) y advertencias
				if ($codigo === '1000' || $esAdvertencia) {
					continue;
				}
				
				if ($numeroArchivo !== null) {
					if (!isset($erroresPorArchivo[$numeroArchivo])) {
						$erroresPorArchivo[$numeroArchivo] = [];
					}
					$erroresPorArchivo[$numeroArchivo][] = $mensaje;
				}
			}

			// Procesar cada factura según su índice
			foreach ($facturas as $index => $factura) {
				if (isset($erroresPorArchivo[$index])) {
					// Factura con errores
					$erroresTexto = json_encode($erroresPorArchivo[$index]);
					
					DB::table('factura')
						->where('nro_factura', $factura->nro_factura)
						->where('anio', $factura->anio)
						->update([
							'estado' => 'Rechazado',
							'mensaje_sin' => $erroresTexto
						]);
					
					// Actualizar regulacion_factura
					DB::table('regulacion_factura')
						->where('nro_factura', $factura->nro_factura)
						->where('anio', $factura->anio)
						->update([
							'estado' => 'FALLIDO',
							'errores' => $erroresTexto
						]);
				} else {
					// Factura aceptada
					DB::table('factura')
						->where('nro_factura', $factura->nro_factura)
						->where('anio', $factura->anio)
						->update([
							'estado' => 'Enviado',
							'aceptado_impuestos' => true,
							'mensaje_sin' => 'Factura validada correctamente'
						]);
					
					// Actualizar regulacion_factura
					DB::table('regulacion_factura')
						->where('nro_factura', $factura->nro_factura)
						->where('anio', $factura->anio)
						->update([
							'estado' => 'COMPLETADO',
							'errores' => null
						]);
				}
			}
			return;
		}

		// Fallback: usar codigosRespuestas si existe (formato antiguo)
		if (isset($resp['codigosRespuestas'])) {
			$respuestas = $resp['codigosRespuestas'];
			
			// Si es una sola factura, convertir a array
			if (!isset($respuestas[0])) {
				$respuestas = [$respuestas];
			}

			foreach ($respuestas as $index => $respuesta) {
				if (!isset($facturas[$index])) {
					continue;
				}

				$factura = $facturas[$index];
				$codigoEstado = isset($respuesta['codigoEstado']) ? (int)$respuesta['codigoEstado'] : null;
				$mensaje = isset($respuesta['mensajesList']['descripcion']) ? $respuesta['mensajesList']['descripcion'] : '';

				// Mapear código de estado
				$nuevoEstado = 'PENDIENTE';
				$aceptado = false;
				if ($codigoEstado === 908 || $codigoEstado === 905) {
					$nuevoEstado = 'ACEPTADA';
					$aceptado = true;
				} elseif ($codigoEstado === 901) {
					$nuevoEstado = 'RECHAZADA';
				}

				DB::table('factura')
					->where('nro_factura', $factura->nro_factura)
					->where('anio', $factura->anio)
					->update([
						'estado' => $nuevoEstado,
						'mensaje_sin' => $mensaje,
						'aceptado_impuestos' => $aceptado
					]);
			}
		}
	}

	/**
	 * Obtiene CUIS vigente
	 * 
	 * @return string
	 */
	private function obtenerCuisVigente()
	{
		$cuis = DB::table('sin_cuis')
			->where('fecha_vigencia', '>', now())
			->orderBy('fecha_vigencia', 'desc')
			->first();

		if (!$cuis) {
			// Si no hay vigente, usar el más reciente (para pruebas)
			$cuis = DB::table('sin_cuis')
				->orderBy('fecha_vigencia', 'desc')
				->first();
			
			if (!$cuis) {
				throw new \RuntimeException('No hay CUIS disponible');
			}
			
			Log::warning('ContingenciaService.cuisNoVigente', [
				'codigo_cuis' => $cuis->codigo_cuis,
				'fecha_vigencia' => $cuis->fecha_vigencia
			]);
		}

		return $cuis->codigo_cuis;
	}
	
	/**
	 * Obtiene CUFD vigente o el más reciente
	 * 
	 * @return string
	 */
	private function obtenerCufdVigente()
	{
		$cufd = DB::table('sin_cufd')
			->where('fecha_vigencia', '>', now())
			->orderBy('fecha_vigencia', 'desc')
			->first();

		if (!$cufd) {
			// Si no hay vigente, usar el más reciente (para pruebas)
			$cufd = DB::table('sin_cufd')
				->orderBy('fecha_vigencia', 'desc')
				->first();
			
			if (!$cufd) {
				throw new \RuntimeException('No hay CUFD disponible. Debe sincronizar CUFD antes de regularizar.');
			}
			
			Log::warning('ContingenciaService.cufdNoVigente', [
				'codigo_cufd' => $cufd->codigo_cufd,
				'fecha_vigencia' => $cufd->fecha_vigencia
			]);
		}

		return $cufd->codigo_cufd;
	}

	/**
	 * Obtiene las fechas de inicio y fin del evento basándose en las facturas
	 * 
	 * Según documentación del SIN: Las fechas deben corresponder al período real de la contingencia
	 * (cuando se emitieron las facturas), NO al momento del envío.
	 * 
	 * @param array $facturas
	 * @return array ['inicio' => string, 'fin' => string]
	 */
	private function obtenerFechasEvento($facturas)
	{
		$fechaMinima = null;
		$fechaMaxima = null;

		foreach ($facturas as $factura) {
			$fechaEmision = strtotime($factura->fecha_emision);
			
			if ($fechaMinima === null || $fechaEmision < $fechaMinima) {
				$fechaMinima = $fechaEmision;
			}
			
			if ($fechaMaxima === null || $fechaEmision > $fechaMaxima) {
				$fechaMaxima = $fechaEmision;
			}
		}

		// Usar las fechas reales de emisión de las facturas
		// Esto representa el período real de la contingencia
		$fechaMinimaCarbon = \Carbon\Carbon::createFromTimestamp($fechaMinima)->timezone('America/La_Paz');
		$fechaMaximaCarbon = \Carbon\Carbon::createFromTimestamp($fechaMaxima)->timezone('America/La_Paz');
		
		$inicio = $fechaMinimaCarbon->format('Y-m-d\TH:i:s.v');
		$fin = $fechaMaximaCarbon->format('Y-m-d\TH:i:s.v');

		Log::info('ContingenciaService.fechasEvento', [
			'fecha_inicio' => $inicio,
			'fecha_fin' => $fin,
			'cantidad_facturas' => count($facturas)
		]);

		return [
			'inicio' => $inicio,
			'fin' => $fin
		];
	}

	/**
	 * Registra un intento de regularización en la tabla regulacion_factura
	 * 
	 * @param array $facturas
	 * @param string $cafc
	 * @param int|null $codigoEvento
	 * @param bool $transaccion
	 * @param string|null $codigoRecepcion
	 * @param string|null $errores
	 * @param bool $esManual
	 */
	private function registrarIntentoRegularizacion(
		$facturas,
		$cafc,
		$codigoEvento,
		$transaccion,
		$codigoRecepcion = null,
		$errores = null,
		$esManual = false
	) {
		// Obtener contexto
		$cuis = null;
		$puntoVenta = null;
		$sucursal = null;
		
		try {
			$cuiRow = DB::table('sin_cuis')
				->where('fecha_vigencia', '>', now())
				->orderBy('fecha_vigencia', 'desc')
				->first();
			if ($cuiRow) {
				$cuis = $cuiRow->codigo_cuis;
			}
		} catch (\Throwable $e) {
			// Ignorar error
		}

		// Registrar cada factura
		foreach ($facturas as $factura) {
			$puntoVenta = isset($factura->codigo_punto_venta) ? $factura->codigo_punto_venta : null;
			$sucursal = isset($factura->codigo_sucursal) ? $factura->codigo_sucursal : null;

			DB::table('regulacion_factura')->insert([
				'nro_factura' => $factura->nro_factura,
				'anio' => $factura->anio,
				'codigo_cafc' => $cafc,
				'codigo_evento_significativo' => $codigoEvento,
				'descripcion' => $transaccion ? 'Regularización exitosa' : 'Intento de regularización fallido',
				'fecha_regularizacion' => now(),
				'codigo_cuis' => $cuis,
				'codigo_punto_venta' => $puntoVenta,
				'codigo_sucursal' => $sucursal,
				'transaccion' => $transaccion,
				'resultado_esperado' => $transaccion ? 'ACEPTADA' : 'ERROR',
				'errores' => $errores,
				'es_manual' => $esManual,
				'codigo_recepcion_paquete' => $codigoRecepcion,
				'estado' => $transaccion ? 'COMPLETADO' : 'FALLIDO',
				'created_at' => now(),
				'updated_at' => now()
			]);
		}
	}
}
