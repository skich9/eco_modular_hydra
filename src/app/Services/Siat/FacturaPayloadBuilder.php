<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FacturaPayloadBuilder
{
    public function buildRecepcionFacturaPayload(array $args)
    {
        $now = Carbon::now('America/La_Paz');
        $modalidad = (int) ($args['modalidad'] ?? 1);
        $docSector = (int) ($args['doc_sector'] ?? 11);

        $xmlPath = null;
        if ($modalidad === 2 && $docSector !== 11) {
            // JSON computarizada (para sectores distintos a educativo)
            $archivo = $this->buildJsonCompraVenta($args, $docSector);
            $archivoBytes = json_encode($archivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // XML para modalidad 1 o sector educativo (11)
            $xmlCrudo = $this->buildXmlSectorEducativo($args, $docSector);
            
            // Firmar XML 
            $nombreFactura = '';
            if (!empty($args['cuf'])) {
                $nombreFactura = (string) $args['cuf'];
            } elseif (!empty($args['numero_factura'])) {
                $nombreFactura = (string) $args['numero_factura'];
            } else {
                $nombreFactura = 'fact_' . uniqid();
            }
            
            $archivoBytes = $xmlCrudo;
            try {
                $firma = new FirmaDigital();
                $rutas = $firma->firmarFactura($nombreFactura, $xmlCrudo);
                if (!empty($rutas['firmado']) && is_file($rutas['firmado'])) {
                    $firmadoContent = @file_get_contents($rutas['firmado']);
                    if ($firmadoContent !== false && $firmadoContent !== '') {
                        $archivoBytes = $firmadoContent;
                        $xmlPath = $rutas['firmado'];
                    }
                }
                if ($xmlPath === null) {
                    $xmlPath = $this->saveXmlDebugCopy($archivoBytes, $args, $docSector);
                }
                Log::debug('FacturaPayloadBuilder.firmaXml', [
                    'nombreFactura' => $nombreFactura,
                    'xmlPath' => $xmlPath,
                    'len' => strlen($archivoBytes),
                ]);
                
                // Validar XML firmado contra el XSD antes de comprimir/enviar
                if ($xmlPath && is_file($xmlPath)) {
                    $xsdPath = base_path('xsd/facturaElectronicaSectorEducativo.xsd');
                    $validator = new XmlXsdValidator();
                    if (!$validator->validar($xmlPath, $xsdPath)) {
                        $err = $validator->mostrarError();
                        Log::error('FacturaPayloadBuilder.xsdValidationFailed', [
                            'xml' => $xmlPath,
                            'xsd' => $xsdPath,
                            'errors' => $err,
                        ]);
                        throw new \RuntimeException('No pasa la validacion del XSD: ' . $err);
                    }
                }
            } catch (\Throwable $e) {
                // Si algo falla (firma o validación XSD), usar XML crudo como debug y abortar flujo
                $xmlPath = $this->saveXmlDebugCopy($archivoBytes, $args, $docSector);
                Log::warning('FacturaPayloadBuilder.firmaXml.error', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Log de inspección del payload sin comprimir (solo primeros caracteres para no saturar logs)
        Log::debug('FacturaPayloadBuilder.rawBytes', [
            'modalidad' => $modalidad,
            'docSector' => $docSector,
            'len' => strlen($archivoBytes),
            'preview' => substr($archivoBytes, 0, 200),
        ]);

        // Según configuración, enviar archivo plano (XML) o comprimido (GZ dentro de .zip)
        $usePlain = (bool) config('sin.archivo_plain', false);
        $archivoBytesForSoap = $archivoBytes;
        if ($usePlain) {
            $hashArchivo = hash('sha256', $archivoBytesForSoap);
            Log::debug('FacturaPayloadBuilder.modoArchivo', [
                'modo' => 'PLANO',
                'len' => strlen($archivoBytesForSoap),
                'hash' => $hashArchivo,
            ]);
        } else {
            // Comprimir en GZ con extensión .zip, tomando el XML desde disco cuando se tenga ruta
            $gzData = $this->compressSingleGzToZip($archivoBytes, $xmlPath);
            $archivoBytesForSoap = $gzData['bytes'];
            $hashArchivo = $gzData['hash'];
            Log::debug('FacturaPayloadBuilder.modoArchivo', [
                'modo' => 'GZ_ZIP',
                'len' => strlen($archivoBytesForSoap),
                'hash' => $hashArchivo,
            ]);
        }

        // Log específico con el archivo base64 completo para usar en pruebas (SoapUI, etc.)
        $archivoB64 = base64_encode($archivoBytesForSoap);
        Log::debug('FacturaPayloadBuilder.archivoBase64', [
            'modo' => $usePlain ? 'PLANO' : 'GZ_ZIP',
            'cuf' => isset($args['cuf']) ? (string)$args['cuf'] : null,
            'archivo_base64' => $archivoB64,
        ]);

        // SIAT valida tolerancia de 5 minutos sobre fechaEnvio; usar tiempo actual en La Paz
        $fechaEnvioIso = $now->format('Y-m-d\\TH:i:s.000');

        $payload = [
            'codigoAmbiente' => (int) ($args['ambiente'] ?? 2),
            'codigoModalidad' => $modalidad,
            'codigoEmision' => (int) ($args['tipo_emision'] ?? 1),
            'nit' => (int) ($args['nit'] ?? 0),
            'codigoSistema' => (string) ($args['cod_sistema'] ?? ''),
            'codigoSucursal' => (int) ($args['sucursal'] ?? 0),
            'codigoPuntoVenta' => (int) ($args['punto_venta'] ?? 0),
            'tipoFacturaDocumento' => (int) ($args['tipo_factura'] ?? 1),
            'codigoDocumentoSector' => $docSector,
            'cuis' => (string) ($args['cuis'] ?? ''),
            'cufd' => (string) ($args['cufd'] ?? ''),
            'cuf' => (string) ($args['cuf'] ?? ''),
            'fechaEnvio' => $fechaEnvioIso,
            // Importante: pasar bytes crudos al SoapClient; él se encarga del base64
            'archivo' => $archivoBytesForSoap,
            'hashArchivo' => $hashArchivo,
        ];

        Log::debug('FacturaPayloadBuilder.buildRecepcionFacturaPayload', [
            'lenArchivo' => strlen($archivoB64),
            'modalidad' => $modalidad,
            'fechaEnvio' => $fechaEnvioIso,
        ]);
        return $payload;
    }

    /**
     * Compresión de una sola factura XML a formato GZ con extensión .zip
     * Replica la lógica de la función 'comprimir' del SGA. Si se proporciona
     * una ruta $xmlFilePath, se usa directamente ese archivo como origen;
     * en caso contrario, se genera un temporal con el contenido $xml.
     *   xml -> archivo .xml -> gzopen(destino .zip, "w9") -> leer .zip -> hash_file(sha256, .zip)
     * Retorna ['bytes' => contenido_zip, 'hash' => sha256(contenido_zip)].
     */
    private function compressSingleGzToZip(string $xml, ?string $xmlFilePath = null): array
    {
        $bytes = $xml;
        $hash = hash('sha256', $xml);
        try {
            $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'siat_fact_' . uniqid();
            $xmlPath = $xmlFilePath && is_file($xmlFilePath) ? $xmlFilePath : ($base . '.xml');
            $zipPath = $base . '.zip';
            
            // Si no hay archivo en disco, guardar XML en un temporal
            if (!$xmlFilePath || !is_file($xmlFilePath)) {
                file_put_contents($xmlPath, $xml);
            }
            
            // Comprimir con gzopen al archivo .zip usando el XML en disco
            $fp = @fopen($xmlPath, 'rb');
            if ($fp !== false) {
                $data = stream_get_contents($fp);
                fclose($fp);
                $gz = @gzopen($zipPath, 'w9');
                if ($gz !== false) {
                    @gzwrite($gz, $data);
                    @gzclose($gz);
                }
            }
            
            if (is_file($zipPath)) {
                // Guardar una copia estable del ZIP comprimido para inspección/reuso
                $destPath = null;
                try {
                    $baseDir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'comprimidos');
                    if (!is_dir($baseDir)) {
                        @mkdir($baseDir, 0775, true);
                    }
                    $zipName = 'factura_' . date('Ymd_His') . '.zip';
                    if ($xmlFilePath && is_file($xmlFilePath)) {
                        $baseName = basename($xmlFilePath, '.xml');
                        if ($baseName !== '') {
                            $zipName = $baseName . '.zip';
                        }
                    }
                    $destPath = $baseDir . DIRECTORY_SEPARATOR . $zipName;
                    @copy($zipPath, $destPath);
                    Log::debug('FacturaPayloadBuilder.compressSingleGzToZip.storeCopy', [
                        'dest' => $destPath,
                        'size' => is_file($destPath) ? filesize($destPath) : null,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('FacturaPayloadBuilder.compressSingleGzToZip.storeCopyError', [ 'error' => $e->getMessage() ]);
                }
                
                // Para calcular bytes y hash, leer directamente desde el ZIP definitivo en disco
                $hashSource = $destPath && is_file($destPath) ? $destPath : $zipPath;
                $zipContent = file_get_contents($hashSource);
                if ($zipContent !== false) {
                    $bytes = $zipContent;
                    $hash = hash_file('sha256', $hashSource, false);
                }
            }
            Log::debug('FacturaPayloadBuilder.compressSingleGzToZip', [
                'xml_len' => strlen($xml),
                'zip_exists' => is_file($zipPath),
                'zip_size' => isset($zipContent) && $zipContent !== false ? strlen($zipContent) : null,
                'hash' => $hash,
            ]);
            if (!$xmlFilePath || !is_file($xmlFilePath)) {
                @unlink($xmlPath);
            }
            @unlink($zipPath);
        } catch (\Throwable $e) {
            // En caso de error, se mantiene el contenido y hash sobre el XML original
        }
        return [ 'bytes' => $bytes, 'hash' => $hash ];
    }

    /**
     * Guarda una copia del XML generado en disco (solo para modalidad XML),
     * en storage/siat_xml para inspección y comparación. Devuelve la ruta
     * del archivo guardado o null si algo falla.
     */
    private function saveXmlDebugCopy(string $xml, array $args, int $docSector): ?string
    {
        try {
            // storage/siat_xml dentro del proyecto Laravel
            $baseDir = storage_path('siat_xml');
            if (!is_dir($baseDir)) {
                @mkdir($baseDir, 0775, true);
            }
            $cuf = isset($args['cuf']) ? (string)$args['cuf'] : '';
            $nro = isset($args['numero_factura']) ? (int)$args['numero_factura'] : 0;
            $ts = date('Ymd_His');
            $safeCuf = $cuf !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $cuf) : 'nocuf';
            $filename = sprintf('factura_%d_%s_%d_%s.xml', (int)$docSector, $safeCuf, $nro, $ts);
            $path = $baseDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($path, $xml);
            Log::debug('FacturaPayloadBuilder.saveXmlDebugCopy', [ 'path' => $path, 'len' => strlen($xml) ]);
            return $path;
        } catch (\Throwable $e) {
            Log::warning('FacturaPayloadBuilder.saveXmlDebugCopy error', [ 'error' => $e->getMessage() ]);
            return null;
        }
    }

    /**
     * Compresión múltiple a tar.gz (no se usa actualmente en el flujo de facturación),
     * pero queda listo para futuras extensiones de envío masivo.
     */
    private function compressMultipleTarGz(array $xmlFiles): ?string
    {
        // $xmlFiles es un array de ['nombre' => 'factura1.xml', 'contenido' => '<xml...>']
        try {
            if (!class_exists('PharData')) {
                return null;
            }
            $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'siat_pkg_' . uniqid();
            $tarPath = $base . '.tar';
            $gzPath = $tarPath . '.gz';
            
            // Crear TAR
            $tar = new \PharData($tarPath);
            foreach ($xmlFiles as $file) {
                $name = isset($file['nombre']) ? (string)$file['nombre'] : ('factura_' . uniqid() . '.xml');
                $content = (string)($file['contenido'] ?? '');
                $tar[$name] = $content;
            }
            
            // Comprimir a GZ (tar.gz)
            $tar->compress(\Phar::GZ);
            if (!is_file($gzPath)) {
                return null;
            }
            $bytes = file_get_contents($gzPath);
            @unlink($tarPath);
            @unlink($gzPath);
            return $bytes !== false ? $bytes : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildJsonCompraVenta(array $args, int $docSector): array
    {
        // Cabecera básica según Compra-Venta; adaptable a otros docSector cuando se requiera
        $fechaIso = Carbon::parse($args['fecha_emision'] ?? Carbon::now())->format('Y-m-d\TH:i:s.000');

        // Método de pago: mapear desde sin_forma_cobro por id_forma_cobro interno
        $codigoMetodoPago = 1; // Efectivo default
        if (!empty($args['id_forma_cobro'])) {
            $m = DB::table('sin_forma_cobro')->where('id_forma_cobro', (string)$args['id_forma_cobro'])->first();
            if ($m && isset($m->codigo_sin)) {
                $codigoMetodoPago = (int) $m->codigo_sin;
            }
        }

        // Leyenda: tomar cualquiera existente
        $leyenda = DB::table('sin_list_leyenda_factura')->value('descripcion_leyenda');
        if (!$leyenda) $leyenda = 'Ley N° 453: Esta factura contribuye al desarrollo del país.';

        // Actividad económica: tomar la primera
        $actividad = DB::table('sin_actividades')->value('codigo_caeb');
        if (!$actividad) $actividad = '00000';

        $cabecera = [
            'nitEmisor' => (int) ($args['nit'] ?? 0),
            'razonSocialEmisor' => (string) ($args['razon_emisor'] ?? 'EMISOR'),
            'municipio' => (string) ($args['municipio'] ?? 'LA PAZ'),
            'telefono' => isset($args['telefono']) ? (int)$args['telefono'] : null,
            'numeroFactura' => (int) ($args['numero_factura'] ?? 0),
            'cuf' => (string) ($args['cuf'] ?? ''),
            'cufd' => (string) ($args['cufd'] ?? ''),
            'codigoSucursal' => (int) ($args['sucursal'] ?? 0),
            'direccion' => (string) ($args['direccion'] ?? 'S/D'),
            'codigoPuntoVenta' => (int) ($args['punto_venta'] ?? 0),
            'fechaEmision' => $fechaIso,
            'nombreRazonSocial' => (string) ($args['cliente']['razon'] ?? 'S/N'),
            'codigoTipoDocumentoIdentidad' => (int) ($args['cliente']['tipo_doc'] ?? 5),
            'numeroDocumento' => (string) ($args['cliente']['numero'] ?? '0'),
            'complemento' => $args['cliente']['complemento'] ?? null,
            'codigoCliente' => (string) ($args['cliente']['codigo'] ?? ($args['cliente']['numero'] ?? '0')),
            'codigoMetodoPago' => (int) $codigoMetodoPago,
            'numeroTarjeta' => null,
            'montoTotal' => (float) ($args['monto_total'] ?? 0),
            'montoTotalSujetoIva' => (float) ($args['monto_total'] ?? 0),
            'codigoMoneda' => 1,
            'tipoCambio' => 1,
            'montoTotalMoneda' => (float) ($args['monto_total'] ?? 0),
            'leyenda' => $leyenda,
            'usuario' => (string) ($args['usuario'] ?? 'system'),
            'codigoDocumentoSector' => $docSector,
        ];

        $detalle = [[
            'actividadEconomica' => (string) $actividad,
            'codigoProductoSin' => (int) ($args['detalle']['codigo_sin'] ?? 0),
            'codigoProducto' => (string) ($args['detalle']['codigo'] ?? 'ITEM'),
            'descripcion' => (string) ($args['detalle']['descripcion'] ?? 'Servicio/Item'),
            'cantidad' => (float) ($args['detalle']['cantidad'] ?? 1),
            'unidadMedida' => (int) ($args['detalle']['unidad_medida'] ?? 1),
            'precioUnitario' => (float) ($args['detalle']['precio_unitario'] ?? ($args['monto_total'] ?? 0)),
            'montoDescuento' => (float) ($args['detalle']['descuento'] ?? 0),
            'subTotal' => (float) ($args['detalle']['subtotal'] ?? ($args['monto_total'] ?? 0)),
        ]];

        return [
            'cabecera' => $cabecera,
            'detalle' => $detalle,
        ];
    }

    private function buildXmlSectorEducativo(array $args, int $docSector): string
    {
        $fechaIso = Carbon::parse($args['fecha_emision'] ?? Carbon::now())->format('Y-m-d\\TH:i:s.000');

        // Catálogos
        $codigoMetodoPago = 1; // Efectivo por defecto
        if (!empty($args['id_forma_cobro'])) {
            $m = DB::table('sin_forma_cobro')->where('id_forma_cobro', (string)$args['id_forma_cobro'])->first();
            if ($m && isset($m->codigo_sin)) {
                $codigoMetodoPago = (int) $m->codigo_sin;
            }
        }
        $leyenda = DB::table('sin_list_leyenda_factura')->value('descripcion_leyenda') ?: 'Ley N° 453: Esta factura contribuye al desarrollo del país.';
        $actividad = DB::table('sin_actividades')->value('codigo_caeb') ?: '00000';

        // Cliente
        $cli = $args['cliente'] ?? [];
        $nombreEst = $args['nombre_estudiante'] ?? ($cli['razon'] ?? 'S/N');
        $periodo = $args['periodo_facturado'] ?? ($args['detalle']['periodo_facturado'] ?? null);

        // Detalle
        $det = $args['detalle'] ?? [];
        $codigoProductoSin = (int) ($det['codigo_sin'] ?? 49111);
        if ($codigoProductoSin <= 0) {
            $codigoProductoSin = 49111;
        }
        $unidadMedida = (int) ($det['unidad_medida'] ?? 1);
        $codigoProducto = (string) ($det['codigo'] ?? '123456');
        $descripcion = (string) ($det['descripcion'] ?? 'Servicio educativo');
        $cantidad = (float) ($det['cantidad'] ?? 1);
        $precioUnitario = (float) ($det['precio_unitario'] ?? ($args['monto_total'] ?? 0));
        $subTotal = (float) ($det['subtotal'] ?? ($args['monto_total'] ?? 0));

        // Emisor
        $razonEmisor = (string) ($args['razon_emisor'] ?? 'EMISOR');
        $municipio = (string) ($args['municipio'] ?? 'La Paz');
        $telefono = isset($args['telefono']) ? (int)$args['telefono'] : null;
        $direccion = (string) ($args['direccion'] ?? 'S/D');

        $xml = '';
        $xml .= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<facturaElectronicaSectorEducativo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="facturaElectronicaSectorEducativo.xsd">';
        $xml .= '<cabecera>';
        $xml .= '<nitEmisor>' . (int)($args['nit'] ?? 0) . '</nitEmisor>';
        $xml .= '<razonSocialEmisor>' . htmlspecialchars($razonEmisor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</razonSocialEmisor>';
        $xml .= '<municipio>' . htmlspecialchars($municipio, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</municipio>';
        $xml .= ($telefono ? '<telefono>' . (int)$telefono . '</telefono>' : '<telefono xsi:nil="true"/>');
        $xml .= '<numeroFactura>' . (int)($args['numero_factura'] ?? 0) . '</numeroFactura>';
        $xml .= '<cuf>' . htmlspecialchars((string)($args['cuf'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</cuf>';
        $xml .= '<cufd>' . htmlspecialchars((string)($args['cufd'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</cufd>';
        $xml .= '<codigoSucursal>' . (int)($args['sucursal'] ?? 0) . '</codigoSucursal>';
        $xml .= '<direccion>' . htmlspecialchars($direccion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</direccion>';
        $xml .= '<codigoPuntoVenta>' . (int)($args['punto_venta'] ?? 0) . '</codigoPuntoVenta>';
        $xml .= '<fechaEmision>' . $fechaIso . '</fechaEmision>';
        $xml .= '<nombreRazonSocial>' . htmlspecialchars((string)($cli['razon'] ?? 'S/N'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</nombreRazonSocial>';
        $xml .= '<codigoTipoDocumentoIdentidad>' . (int)($cli['tipo_doc'] ?? 5) . '</codigoTipoDocumentoIdentidad>';
        $xml .= '<numeroDocumento>' . htmlspecialchars((string)($cli['numero'] ?? '0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</numeroDocumento>';
        $xml .= (!empty($cli['complemento']) ? ('<complemento>' . htmlspecialchars((string)$cli['complemento'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</complemento>') : '<complemento xsi:nil="true"/>' );
        $xml .= '<codigoCliente>' . htmlspecialchars((string)($cli['codigo'] ?? ($cli['numero'] ?? '0')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</codigoCliente>';
        $xml .= '<nombreEstudiante>' . htmlspecialchars((string)$nombreEst, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</nombreEstudiante>';
        $valorPeriodo = (string)($periodo ?? 'SIN PERIODO');
        if ($valorPeriodo === '') {
            $valorPeriodo = 'SIN PERIODO';
        }
        $xml .= '<periodoFacturado>' . htmlspecialchars($valorPeriodo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</periodoFacturado>';
        $xml .= '<codigoMetodoPago>' . (int)$codigoMetodoPago . '</codigoMetodoPago>';
        $xml .= '<numeroTarjeta xsi:nil="true"/>';
        $xml .= '<montoTotal>' . number_format((float)($args['monto_total'] ?? 0), 2, '.', '') . '</montoTotal>';
        $xml .= '<montoTotalSujetoIva>' . number_format((float)($args['monto_total'] ?? 0), 2, '.', '') . '</montoTotalSujetoIva>';
        $xml .= '<codigoMoneda>1</codigoMoneda>';
        $xml .= '<tipoCambio>1</tipoCambio>';
        $xml .= '<montoTotalMoneda>' . number_format((float)($args['monto_total'] ?? 0), 2, '.', '') . '</montoTotalMoneda>';
        $xml .= '<montoGiftCard xsi:nil="true"/>';
        $xml .= '<descuentoAdicional xsi:nil="true"/>';
        $xml .= '<codigoExcepcion xsi:nil="true"/>';
        $xml .= '<cafc xsi:nil="true"/>';
        $xml .= '<leyenda>' . htmlspecialchars($leyenda, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</leyenda>';
        $xml .= '<usuario>' . htmlspecialchars((string)($args['usuario'] ?? 'system'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</usuario>';
        $xml .= '<codigoDocumentoSector>' . (int)$docSector . '</codigoDocumentoSector>';
        $xml .= '</cabecera>';
        $xml .= '<detalle>';
        $xml .= '<actividadEconomica>' . htmlspecialchars((string)$actividad, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</actividadEconomica>';
        $xml .= '<codigoProductoSin>' . (int)$codigoProductoSin . '</codigoProductoSin>';
        $xml .= '<codigoProducto>' . htmlspecialchars($codigoProducto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</codigoProducto>';
        $xml .= '<descripcion>' . htmlspecialchars($descripcion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</descripcion>';
        $xml .= '<cantidad>' . number_format($cantidad, 2, '.', '') . '</cantidad>';
        $xml .= '<unidadMedida>' . (int)$unidadMedida . '</unidadMedida>';
        $xml .= '<precioUnitario>' . number_format($precioUnitario, 2, '.', '') . '</precioUnitario>';
        $xml .= '<montoDescuento xsi:nil="true"/>';
        $xml .= '<subTotal>' . number_format($subTotal, 2, '.', '') . '</subTotal>';
        $xml .= '</detalle>';
        $xml .= '</facturaElectronicaSectorEducativo>';
        return $xml;
    }
}
