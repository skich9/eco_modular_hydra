<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacturaPdfService
{
	/**
	 * Convierte un número a su representación literal en español
	 */
	private function numeroALiteral($numero)
	{
		$entero = floor($numero);
		$decimal = round(($numero - $entero) * 100);

		$unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
		$decenas = ['', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
		$especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
		$centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

		if ($entero == 0) {
			return 'CERO ' . str_pad($decimal, 2, '0', STR_PAD_LEFT) . '/100 Bolivianos';
		}

		$literal = '';

		// Miles
		if ($entero >= 1000) {
			$miles = floor($entero / 1000);
			if ($miles == 1) {
				$literal .= 'MIL ';
			} else {
				$literal .= $this->convertirGrupo($miles, $unidades, $decenas, $especiales, $centenas) . ' MIL ';
			}
			$entero = $entero % 1000;
		}

		// Centenas, decenas y unidades
		if ($entero > 0) {
			$literal .= $this->convertirGrupo($entero, $unidades, $decenas, $especiales, $centenas);
		}

		return trim($literal) . ' ' . str_pad($decimal, 2, '0', STR_PAD_LEFT) . '/100 Bolivianos';
	}

	private function convertirGrupo($numero, $unidades, $decenas, $especiales, $centenas)
	{
		$literal = '';

		// Centenas
		$c = floor($numero / 100);
		if ($c > 0) {
			if ($c == 1 && $numero == 100) {
				$literal .= 'CIEN ';
			} else {
				$literal .= $centenas[$c] . ' ';
			}
		}

		$numero = $numero % 100;

		// Decenas y unidades
		if ($numero >= 10 && $numero < 20) {
			$literal .= $especiales[$numero - 10];
		} elseif ($numero >= 20) {
			$d = floor($numero / 10);
			$u = $numero % 10;
			if ($u > 0) {
				$literal .= $decenas[$d] . ' Y ' . $unidades[$u];
			} else {
				$literal .= $decenas[$d];
			}
		} elseif ($numero > 0) {
			$literal .= $unidades[$numero];
		}

		return $literal;
	}

	/**
	 * Genera el PDF de la factura. Si $anulado=true, aplica marca/etiqueta ANULADO.
	 * Retorna la ruta absoluta del PDF generado.
	 */
	public function generate($anio, $nro, $anulado = false)
	{
		$anio = (int) $anio;
		$nro = (int) $nro;
		$query = DB::table('factura')
			->where('anio', $anio)
			->where('nro_factura', $nro)
			->orderByDesc('created_at')
			->orderByDesc('codigo_sucursal')
			->orderByDesc('codigo_punto_venta');
		$row = $query->first();
		if (!$row) {
			throw new \RuntimeException('Factura no encontrada');
		}
		Log::info('FacturaPdfService.generate.row', [
			'anio' => $anio,
			'nro' => $nro,
			'codigo_sucursal' => isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : null,
			'codigo_punto_venta' => isset($row->codigo_punto_venta) ? (string)$row->codigo_punto_venta : null,
			'cod_ceta' => isset($row->cod_ceta) ? (int)$row->cod_ceta : null,
			'id_usuario' => isset($row->id_usuario) ? (int)$row->id_usuario : null,
			'fecha_emision' => isset($row->fecha_emision) ? (string)$row->fecha_emision : null,
		]);

		$detalles = [];
		try {
			if (DB::getSchemaBuilder()->hasTable('factura_detalle')) {
				$det = DB::table('factura_detalle')
					->where('anio', $anio)
					->where('nro_factura', $nro)
					->where('codigo_sucursal', isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : 0)
					->where('codigo_punto_venta', isset($row->codigo_punto_venta) ? (string)$row->codigo_punto_venta : '0')
					->orderBy('id_detalle')
					->get();
				foreach ($det as $d) {
					$codigo = isset($d->codigo) ? (string)$d->codigo : '';
					$desc = isset($d->descripcion) ? $d->descripcion : 'Item';
					$cant = isset($d->cantidad) ? $d->cantidad : 1;
					$precioUnit = isset($d->precio_unitario) ? $d->precio_unitario : (isset($d->subtotal) ? $d->subtotal : 0);
					$descuento = isset($d->descuento) ? $d->descuento : 0;
					$subt = isset($d->subtotal) ? $d->subtotal : 0;
					$unidadMedida = isset($d->unidad_medida) ? (int)$d->unidad_medida : 58;

					// Mapear código de unidad de medida a nombre
					$nombreUnidad = 'UNIDAD';
					if ($unidadMedida == 1) {
						$nombreUnidad = 'UNIDAD (SERVICIOS)';
					} elseif ($unidadMedida == 58) {
						$nombreUnidad = 'UNIDAD (SERVICIOS)';
					}

					$codigoSin = isset($d->codigo_sin) ? (int)$d->codigo_sin : 99100;
					$codigoInterno = isset($d->codigo_interno) ? (int)$d->codigo_interno : null;

					$detalles[] = [
						'codigo' => $codigo,
						'codigo_sin' => $codigoSin,
						'codigo_interno' => $codigoInterno,
						'descripcion' => (string)$desc,
						'cantidad' => (float)$cant,
						'precio' => (float)$precioUnit,
						'descuento' => (float)$descuento,
						'subtotal' => (float)$subt,
						'unidad_medida' => $nombreUnidad,
					];
				}
			}
		} catch (\Throwable $e) {}

		// Datos de la factura
		$fecha = isset($row->fecha_emision) ? (string)$row->fecha_emision : '';
		try { if ($fecha === '') { $fecha = date('Y-m-d H:i:s'); } } catch (\Throwable $e) { $fecha = date('Y-m-d H:i:s'); }
		$clienteTemp = isset($row->cliente) ? $row->cliente : (isset($row->razon) ? $row->razon : 'S/N');
		$cliente = (string)$clienteTemp;
		$cod_ceta = isset($row->cod_ceta) ? (int)$row->cod_ceta : 0;

		// Obtener datos del estudiante y del cliente
		$nit_cliente = '0';
		$complemento = '';
		$nombre_estudiante = $cliente;

		if ($cod_ceta > 0) {
			try {
				// Obtener datos del estudiante desde tabla estudiantes (plural)
				$estudiante = DB::table('estudiantes')
					->where('cod_ceta', $cod_ceta)
					->first();

				if ($estudiante) {
					// Nombre completo del estudiante
					$nombres = isset($estudiante->nombres) ? trim((string)$estudiante->nombres) : '';
					$ap_paterno = isset($estudiante->ap_paterno) ? trim((string)$estudiante->ap_paterno) : '';
					$ap_materno = isset($estudiante->ap_materno) ? trim((string)$estudiante->ap_materno) : '';
					$nombre_estudiante = trim($nombres . ' ' . $ap_paterno . ' ' . $ap_materno);
					if (empty($nombre_estudiante)) {
						$nombre_estudiante = $cliente;
					}

					// Prioridad 1: Obtener NIT/CI desde el XML de la factura (datos del request/cobro)
					try {
						$xmlPath = storage_path('siat_xml/xmls/' . $row->cuf . '.xml');
						if (file_exists($xmlPath)) {
							$xmlContent = file_get_contents($xmlPath);
							if (preg_match('/<numeroDocumento>(.*?)<\/numeroDocumento>/', $xmlContent, $matches)) {
								$nit_cliente = trim($matches[1]);
							}
							if (preg_match('/<complemento>(.*?)<\/complemento>/', $xmlContent, $matches)) {
								$complemento = trim($matches[1]);
							}
						}
					} catch (\Throwable $e) {
						Log::warning('FacturaPdfService.generate.xml_error', ['error' => $e->getMessage()]);
					}

					// Prioridad 2: Si no se encontró en XML, usar doc_presentados del estudiante
					if ($nit_cliente === '0') {
						try {
							$doc = DB::table('doc_presentados')
								->where('cod_ceta', $cod_ceta)
								->whereIn('nombre_doc', ['CI', 'CARNET DE IDENTIDAD', 'CEX', 'CEDULA DE EXTRANJERIA', 'PASAPORTE', 'NIT'])
								->orderByRaw("FIELD(nombre_doc, 'CI', 'CARNET DE IDENTIDAD', 'CEX', 'CEDULA DE EXTRANJERIA', 'PASAPORTE', 'NIT')")
								->first();

							if ($doc && isset($doc->numero_doc)) {
								$nit_cliente = (string)$doc->numero_doc;
								if (isset($doc->complemento) && $doc->complemento !== '') {
									$complemento = (string)$doc->complemento;
								}
							}
						} catch (\Throwable $e) {
							Log::warning('FacturaPdfService.generate.doc_error', ['error' => $e->getMessage()]);
						}
					}
				}
			} catch (\Throwable $e) {
				Log::warning('FacturaPdfService.generate.estudiante_error', ['error' => $e->getMessage()]);
			}
		}
		$monto = isset($row->monto_total) ? (float)$row->monto_total : 0;
		$cuf = isset($row->cuf) ? (string)$row->cuf : '';
		$codigo_control = isset($row->codigo_control) ? (string)$row->codigo_control : '';
		$sucursal = isset($row->codigo_sucursal) ? (int)$row->codigo_sucursal : 0;
		$pv = '';
		if (isset($row->codigo_punto_venta) && $row->codigo_punto_venta !== '' && $row->codigo_punto_venta !== '0') {
			$pv = (string)$row->codigo_punto_venta;
		}
		// Si el punto de venta en la factura es 0 o vacío, intentar inferirlo desde sin_cufd usando el codigo_cufd
		if ($pv === '' || $pv === '0') {
			try {
				if (!empty($row->codigo_cufd)) {
					$cufdLookup = DB::table('sin_cufd')
						->where('codigo_cufd', (string)$row->codigo_cufd)
						->orderBy('fecha_vigencia', 'desc')
						->first();
					if ($cufdLookup) {
						if (isset($cufdLookup->codigo_punto_venta)) {
							$pv = (string)$cufdLookup->codigo_punto_venta;
						}
						if ($sucursal === 0 && isset($cufdLookup->codigo_sucursal)) {
							$sucursal = (int)$cufdLookup->codigo_sucursal;
						}
					}
				}
			} catch (\Throwable $e) {
				Log::warning('FacturaPdfService.generate.pv_infer_error', ['error' => $e->getMessage()]);
			}
		}
		if ($pv === '' || $pv === null) {
			$pv = '0';
		}
		$estado = isset($row->estado) ? (string)$row->estado : '';

		// Usuario que registró el cobro
		$usuarioNombre = 'Sistema';
		try {
			if (isset($row->id_usuario)) {
				$usr = DB::table('usuarios')->where('id_usuario', (int)$row->id_usuario)->first();
				if ($usr) {
					if (isset($usr->nickname) && $usr->nickname !== '') {
						$usuarioNombre = (string)$usr->nickname;
					} elseif (isset($usr->usuario) && $usr->usuario !== '') {
						$usuarioNombre = (string)$usr->usuario;
					} elseif (isset($usr->nombre) && $usr->nombre !== '') {
						$usuarioNombre = (string)$usr->nombre;
					}
				}
			}
		} catch (\Throwable $e) {
			Log::warning('FacturaPdfService.generate.usuario_error', ['error' => $e->getMessage()]);
		}

		// Obtener período facturado desde la factura
		$periodoFacturado = isset($row->periodo_facturado) && $row->periodo_facturado !== '' ? (string)$row->periodo_facturado : '2/2025';
		$gestion = $periodoFacturado;

		// Datos de configuración
		$nit = config('sin.nit', '388386029');
		$razon_social = config('sin.razon_social', 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L.');
		$municipio = config('sin.municipio', 'COCHABAMBA');
		$telefono = config('sin.telefono', '4581736');

		// Obtener datos de sin_cufd para dirección (priorizar CUFD de la factura y luego sucursal/pv)
		$cufd_data = null;
		$direccion = '';
		try {
			// 1) Si la factura tiene codigo_cufd, usarlo directamente
			if (!empty($row->codigo_cufd)) {
				$cufd_data = DB::table('sin_cufd')
					->where('codigo_cufd', (string)$row->codigo_cufd)
					->orderBy('fecha_vigencia', 'desc')
					->first();
			}
			// 2) Si no se encontró por codigo_cufd, buscar por sucursal/pv con vigencia futura
			if (!$cufd_data) {
				$cufd_data = DB::table('sin_cufd')
					->where('codigo_sucursal', $sucursal)
					->where('codigo_punto_venta', (string)$pv)
					->where('fecha_vigencia', '>', now())
					->orderBy('fecha_vigencia', 'desc')
					->first();
			}
			// 3) Como último recurso, usar el último CUFD registrado para esa sucursal/pv
			if (!$cufd_data) {
				$cufd_data = DB::table('sin_cufd')
					->where('codigo_sucursal', $sucursal)
					->where('codigo_punto_venta', (string)$pv)
					->orderBy('fecha_vigencia', 'desc')
					->first();
			}
			if ($cufd_data && isset($cufd_data->direccion) && $cufd_data->direccion !== '') {
				$direccion = (string)$cufd_data->direccion;
			}
		} catch (\Throwable $e) {
			Log::warning('FacturaPdfService.generate.cufd_error', ['error' => $e->getMessage()]);
		}

		// Si no hay dirección en CUFD, usar valores por defecto
		if (empty($direccion)) {
			$direccion = 'ZONA: NOMBRE DE LA ZONA - AVENIDA: TIPO DE AVENIDA - NÚMERO: NÚMERO DE DIRECCIÓN';
		}

		// Agregar salto de línea antes de TELEFONO:
		$direccion = str_replace(', TELEFONO:', '<br>TELEFONO:', $direccion);

		// Formatear fecha en horario de Bolivia (America/La_Paz) en formato 12 horas (AM/PM)
		try {
			$tz = new \DateTimeZone('America/La_Paz');
			$fechaDT = new \DateTime($fecha, $tz);
			$fechaDT->setTimezone($tz);
			$hora = (int)$fechaDT->format('h');
			$minutos = $fechaDT->format('i');
			$ampm = $fechaDT->format('A');
			$fechaFormateada = $fechaDT->format('d/m/Y') . ' ' . $hora . ':' . $minutos . ' ' . $ampm;
		} catch (\Throwable $e) {
			$fechaFormateada = $fecha;
		}

		// Generar URL del QR del SIN (el QR se genera en el servidor del SIN)
		$qrUrl = config('sin.qr_url', 'https://pilotosiat.impuestos.gob.bo/consulta/QR');
		$qrUrl .= '?nit=' . $nit . '&cuf=' . $cuf . '&numero=' . $nro . '&t=1';
		// Generar imagen QR local (similar al SGA) y embeber como data URI
		$qrContent = $qrUrl;
		$qrDataUrl = null;
		try {
			$qrDir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'qrcode');
			if (!is_dir($qrDir)) { @mkdir($qrDir, 0775, true); }
			$qrPng = $qrDir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '_qr.png';
			// Intentar usar la librería local incluida en sga_refs
			$libPath = base_path('sga_refs' . DIRECTORY_SEPARATOR . 'PDFQR y generacion de factura' . DIRECTORY_SEPARATOR . 'qrcode' . DIRECTORY_SEPARATOR . 'phpqrcode.php');
			if (file_exists($libPath)) {
				require_once $libPath;
				// Generar con alta nitidez: ECC=M, módulo grande y zona tranquila amplia
				\QRcode::png($qrContent, $qrPng, 'M', 8, 4);
				$pngBytes = @file_get_contents($qrPng);
				if ($pngBytes !== false && strlen($pngBytes) > 0) {
					$qrDataUrl = 'data:image/png;base64,' . base64_encode($pngBytes);
				}
			}
			// Fallback remoto si falla la generación local
			if (!$qrDataUrl) {
				$remote = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=4&ecc=M&data=' . urlencode($qrContent);
				$pngBytes = @file_get_contents($remote);
				if ($pngBytes !== false && strlen($pngBytes) > 0) {
					$qrDataUrl = 'data:image/png;base64,' . base64_encode($pngBytes);
				}
			}
		} catch (\Throwable $e) {
			Log::warning('FacturaPdfService.generate.qr_error', ['error' => $e->getMessage()]);
		}
		$qrFinalSrc = $qrDataUrl ?: ('https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=4&ecc=M&data=' . urlencode($qrContent));

		// Leyenda aleatoria desde SIN (fallback a una por defecto)
		$leyenda = 'Ley Nº 453: El proveedor de servicios debe habilitar medios e instrumentos para efectuar consultas y reclamaciones.';
		try {
			if (DB::getSchemaBuilder()->hasTable('sin_list_leyenda_factura')) {
				$rowLey = DB::table('sin_list_leyenda_factura')->inRandomOrder()->first();
				if ($rowLey && isset($rowLey->descripcion_leyenda) && $rowLey->descripcion_leyenda !== '') {
					$leyenda = (string)$rowLey->descripcion_leyenda;
				}
			}
		} catch (\Throwable $e) {
			Log::warning('FacturaPdfService.generate.leyenda_error', ['error' => $e->getMessage()]);
		}

		// Construir filas de detalles (formato rollo del SGA)
		$rowsHtml = '';
		$subtotal = 0;
		foreach ($detalles as $d) {
			$subtotal += $d['subtotal'];
			// Usar codigo_interno si existe y no es 0, sino codigo_sin
			// Nota: Si codigo_interno es NULL o 0, mostrar codigo_sin
			$codigoInterno = isset($d['codigo_interno']) ? (int)$d['codigo_interno'] : 0;
			$codigoMostrar = ($codigoInterno > 0) ? $codigoInterno : $d['codigo_sin'];
			// Log para debug
			Log::debug('PDF codigo selection', ['codigo_interno' => $codigoInterno, 'codigo_sin' => $d['codigo_sin'], 'mostrar' => $codigoMostrar]);
			$codigoDesc = $codigoMostrar ? htmlspecialchars($codigoMostrar) . ' - ' : '';
			$descripcionRaw = $d['descripcion'];

			// Transformar "Mensualidad - Cuota X (Parcial)" a "Mens. [Mes] (Parcial)" basándose en gestión
			if (preg_match('/Mensualidad\s*-\s*Cuota\s*(\d+)(\s*\(Parcial\))?/i', $descripcionRaw, $matches)) {
				$numeroCuota = (int)$matches[1];
				$parcialTexto = isset($matches[2]) ? $matches[2] : '';
				$gestion = $gestion;
				Log::debug('PDF transformacion mes', [
					'descripcion_original' => $descripcionRaw,
					'numero_cuota' => $numeroCuota,
					'parcial_texto' => $parcialTexto,
					'gestion' => $gestion
				]);
				$meses = [];

				// Determinar meses según gestión
				if (strpos($gestion, '1/') === 0) {
					// Gestión 1: Cuota 1=Febrero, 2=Marzo, 3=Abril, 4=Mayo, 5=Junio
					$meses = [1 => 'Febrero', 2 => 'Marzo', 3 => 'Abril', 4 => 'Mayo', 5 => 'Junio'];
				} elseif (strpos($gestion, '2/') === 0) {
					// Gestión 2: Cuota 1=Julio, 2=Agosto, 3=Septiembre, 4=Octubre, 5=Noviembre
					$meses = [1 => 'Julio', 2 => 'Agosto', 3 => 'Septiembre', 4 => 'Octubre', 5 => 'Noviembre'];
				}

				if (isset($meses[$numeroCuota])) {
					$descripcionRaw = preg_replace('/Mensualidad\s*-\s*Cuota\s*\d+(\s*\(Parcial\))?/i', 'Mens. ' . $meses[$numeroCuota] . $parcialTexto, $descripcionRaw);
					Log::debug('PDF transformacion mes resultado', ['descripcion_transformada' => $descripcionRaw]);
				}
			}

			$descripcion = htmlspecialchars($descripcionRaw);

		// Dividir solo la descripción (sin el código) si es muy larga
		$maxLength = 50;
		$lineas = [];

		if (strlen($descripcion) > $maxLength) {
			$palabras = explode(' ', $descripcion);
			$lineaActual = '';

			foreach ($palabras as $palabra) {
				$testLinea = $lineaActual === '' ? $palabra : $lineaActual . ' ' . $palabra;

				if (strlen($testLinea) <= $maxLength) {
					$lineaActual = $testLinea;
				} else {
					if ($lineaActual !== '') {
						$lineas[] = $lineaActual;
					}
					$lineaActual = $palabra;
				}
			}

			if ($lineaActual !== '') {
				$lineas[] = $lineaActual;
			}
		} else {
			$lineas[] = $descripcion;
		}

		// Agregar código solo a la primera línea
		$lineas[0] = $codigoDesc . $lineas[0];

		// Generar HTML con saltos de línea
		$descripcionHtml = implode('<br>', $lineas);

		$rowsHtml .= '<tr>
			<td style="text-align:left; font-weight:bold; line-height:1.3;">' . $descripcionHtml . '</td>
		</tr>
		<tr>
			<td class="left-text">Unidad de medida: ' . htmlspecialchars($d['unidad_medida']) . '</td>
		</tr>
		<tr>
			<td style="padding-right:2mm;">' . number_format($d['cantidad'], 2, '.', '') . ' X ' . number_format($d['precio'], 2, '.', '') . ' - ' . number_format($d['descuento'], 2, '.', '') . '</td>
			<td class="right-text" style="padding-right:2mm;">' . number_format($d['subtotal'], 2, '.', '') . '</td>
		</tr>';
		}

		$water = $anulado
			? '<div class="watermark-anulado">ANULADO</div>'
			  . '<div class="watermark-sinlegal top">SIN VALOR LEGAL</div>'
			  . '<div class="watermark-sinlegal bottom">SIN VALOR LEGAL</div>'
			: '';

		$texto_sucursal = 'CASA MATRIZ';
		if ($sucursal != 0) {
			$labels = [];
			try {
				$labels = config('sin.sucursal_labels', []);
			} catch (\Throwable $e) {
				$labels = [];
			}
			if (is_array($labels) && array_key_exists($sucursal, $labels) && $labels[$sucursal] !== '') {
				$texto_sucursal = 'SUCURSAL: ' . (string)$labels[$sucursal];
			} else {
				$texto_sucursal = 'SUCURSAL N. ' . $sucursal;
			}
		}

		$html = '
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<style>
		@page {
			margin: 3mm 2mm;
			size: 75mm 279mm;
		}
		body {
			font-family: Arial, Helvetica, sans-serif;
			font-size: 7pt;
			margin: 0;
			padding: 0;
			width: 71mm;
		}
		.paddin-body { padding: 2mm; }
		.text-size { font-size: 7pt; }
		.text-size2 { font-size: 6pt; }
		.text-center { text-align: center; }
		.left-text { text-align: left; }
		.right-text { text-align: right; }
		.line-segmentado {
			border-bottom: 1px dashed #000;
			padding-bottom: 0.3mm;
			margin-bottom: 2mm;
		}
		table {
			width: 95%;
			border-collapse: collapse;
			margin: 0 auto;
			margin-left: 2mm;
		}
		table th, table td {
			padding: 0.2mm;
			vertical-align: top;
			line-height: 1;
		}
		table th {
			text-align: right;
			padding-right: 1mm;
			width: 52%;
			font-weight: bold;
			white-space: nowrap;
		}
		table td {
			text-align: left;
			padding-left: 1mm;
		}
		strong { font-weight: bold; }
		p {
			margin: 1mm 0;
			word-wrap: break-word;
			overflow-wrap: break-word;
		}
		span {
			display: block;
			text-align: center;
			margin: 2mm 0;
		}
		.cuf-text {
			word-wrap: break-word;
			overflow-wrap: break-word;
			word-break: break-all;
			max-width: 100%;
			font-size: 6pt;
			line-height: 1.3;
			padding: 0 2mm;
		}
		.center-wrap {
			text-align: center;
			margin: 2pt 3mm;
			width: auto;
			padding: 0;
			word-wrap: break-word;
			overflow-wrap: break-word;
			word-break: normal;
			line-height: 1.0;
		}
		.qr-img {
			image-rendering: pixelated;
		}
		.watermark-anulado {
			position: fixed;
			top: 40%;
			left: 0;
			right: 0;
			text-align: center;
			font-size: 48pt;
			color: #d00000;
			opacity: 0.18;
			transform: rotate(-45deg);
			transform-origin: center;
		}
		.watermark-sinlegal {
			position: fixed;
			left: 0;
			right: 0;
			text-align: center;
			font-size: 18pt;
			color: #bfbfbf;
			opacity: 0.35;
		}
		.watermark-sinlegal.top { top: 18%; }
		.watermark-sinlegal.bottom { top: 78%; }
		</style>
	<title>FACTURA ' . $nro . '/' . $anio . '</title>
</head>
<body>
	' . $water . '

	<div class="paddin-body text-size">
		<div class="text-center">
			<strong>FACTURA</strong><br>
			<strong>CON DERECHO A CRÉDITO FISCAL</strong>
			<p>' . htmlspecialchars($razon_social) . '<br>
			' . $texto_sucursal . '</p>
			<p>No. Punto de Venta ' . $pv . '<br>
			' . $direccion . '<br>
			' . htmlspecialchars($municipio) . '</p>
		</div>
		<span>----------------------------------------------------------------------</span>
		<div class="text-center">
			<strong>NIT</strong>
			<p>' . htmlspecialchars($nit) . '</p>
			<strong>FACTURA Nº</strong>
			<p>' . $nro . '</p>
			<strong>CÓD. AUTORIZACIÓN</strong>
			<p class="cuf-text">' . htmlspecialchars($cuf) . '</p>
		</div>
		<span>----------------------------------------------------------------------</span>
		<table class="text-size">
			<tbody>
				<tr>
					<th class="right-text" style="text-align: right; font-weight: bold;">NOMBRE/RAZÓN SOCIAL:</th>
					<td class="left-text" style="text-align: left;">' . htmlspecialchars($cliente) . '</td>
				</tr>
				<tr>
					<th class="right-text">NIT/CI/CEX:</th>
					<td class="left-text">' . htmlspecialchars($nit_cliente) . ($complemento ? '-' . htmlspecialchars($complemento) : '') . '</td>
				</tr>
				<tr>
					<th class="right-text">COD. CLIENTE:</th>
					<td class="left-text">' . htmlspecialchars($nit_cliente) . '</td>
				</tr>
				<tr>
					<th class="right-text">FECHA DE EMISIÓN:</th>
					<td class="left-text">' . htmlspecialchars($fechaFormateada) . '</td>
				</tr>
				<tr>
					<th class="right-text">PERÍODO FACTURADO:</th>
					<td class="left-text">' . htmlspecialchars($periodoFacturado) . '</td>
				</tr>
				<tr>
					<th class="right-text">NOMBRE ESTUDIANTE:</th>
					<td class="left-text">' . htmlspecialchars($nombre_estudiante) . '</td>
				</tr>
			</tbody>
		</table>
		<span>----------------------------------------------------------------------</span>
		<div>
			<p class="text-center"><strong>DETALLE</strong></p>
			<table class="text-size">' . $rowsHtml . '</table>
		</div>
		<span>................................................................................</span>
		<div>
			<table class="text-size">
				<tbody>
					<tr>
						<th class="right-text">SUBTOTAL Bs</th>
						<th class="right-text"></th>
						<td class="right-text"></td>
						<td class="right-text">' . number_format($subtotal, 2, '.', '') . '</td>
					</tr>
					<tr>
						<th class="right-text">DESCUENTO Bs</th>
						<th class="right-text"></th>
						<td class="right-text"></td>
						<td class="right-text">0.00</td>
					</tr>
					<tr>
						<th class="right-text">TOTAL Bs</th>
						<th class="right-text"></th>
						<td class="right-text"></td>
						<td class="right-text">' . number_format($monto, 2, '.', '') . '</td>
					</tr>
					<tr>
						<th class="right-text">MONTO GIFT CARD Bs</th>
						<th class="right-text"></th>
						<td class="right-text"></td>
						<td class="right-text">0.00</td>
					</tr>
					<tr>
						<th class="right-text">MONTO A PAGAR Bs</th>
						<th class="left-text"></th>
						<td class="right-text"></td>
						<td class="right-text">' . number_format($monto, 2, '.', '') . '</td>
					</tr>
					<tr>
						<th class="right-text">IMPORTE BASE CRÉDITO FISCAL</th>
						<th class="left-text"></th>
						<td class="right-text" style="width:10%;"></td>
						<td class="right-text">' . number_format($monto, 2, '.', '') . '</td>
					</tr>
				</tbody>
			</table>
			<p class="left-text" style="margin-top:2mm; font-size:6pt; padding-left:2mm;">Son: ' . $this->numeroALiteral($monto) . '</p>
		</div>
		<span>----------------------------------------------------------------------</span>
		<div class="text-center text-size2">
			<p class="center-wrap">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS. EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</p>
			<p class="center-wrap">' . htmlspecialchars($leyenda) . '</p>
			<p class="center-wrap">"Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea"</p>
			<img src="' . $qrFinalSrc . '" alt="QR Code" class="qr-img" style="width:40mm; height:40mm; image-rendering: pixelated;" />
		</div>
		<span>----------------------------------------------------------------------</span>
		<div class="left-text text-size2">
			<p style="padding-left:2mm;">Usuario: ' . htmlspecialchars($usuarioNombre) . ' - Fecha: ' . htmlspecialchars($fechaFormateada) . '</p>
			<p style="padding-left:2mm;">Código CETA: ' . htmlspecialchars((string)$cod_ceta) . '</p>
		</div>
	</div>
</body>
</html>
';

		try {
			Log::debug('FacturaPdfService.generate.html', [
				'anio' => $anio,
				'nro' => $nro,
				'html_length' => strlen($html),
				'html_preview' => substr($html, 0, 200)
			]);

			$dompdf = new Dompdf([ 'isRemoteEnabled' => true ]);
			$dompdf->loadHtml($html, 'UTF-8');
			// Forzar tamaño rollo 75mm x 279mm en puntos (ancho, alto)
			$dompdf->setPaper([75*2.83464567, 279*2.83464567]);
			$dompdf->render();
			$pdf = $dompdf->output();

			if (empty($pdf)) {
				throw new \RuntimeException('PDF generado está vacío');
			}

			$dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'facturas');
			if (!is_dir($dir)) {
				if (!@mkdir($dir, 0775, true)) {
					throw new \RuntimeException('No se pudo crear directorio: ' . $dir);
				}
			}

			$suffix = $anulado ? '_ANULADO' : '';
			$path = $dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . $suffix . '.pdf';

			$written = @file_put_contents($path, $pdf);
			if ($written === false) {
				throw new \RuntimeException('No se pudo escribir archivo PDF: ' . $path);
			}

			Log::debug('FacturaPdfService.generate.success', [
				'anio' => $anio,
				'nro' => $nro,
				'anulado' => $anulado,
				'path' => $path,
				'size' => strlen($pdf),
				'written' => $written
			]);

			return $path;
		} catch (\Throwable $e) {
			Log::error('FacturaPdfService.generate.error', [
				'anio' => $anio,
				'nro' => $nro,
				'anulado' => $anulado,
				'error' => $e->getMessage()
			]);
			throw $e;
		}
	}

	public function generateAnulada($anio, $nro)
	{
		return $this->generate($anio, $nro, true);
	}

	public function generateAnuladaStrict($anio, $nro)
	{
		return $this->generate($anio, $nro, true);
	}
}
