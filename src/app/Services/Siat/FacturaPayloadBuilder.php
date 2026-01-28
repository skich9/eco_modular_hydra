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
        $modalidad = (int) (isset($args['modalidad']) ? $args['modalidad'] : 1);
        $docSector = (int) (isset($args['doc_sector']) ? $args['doc_sector'] : 11);

        $xmlPath = null;
        if ($modalidad === 2 && $docSector !== 11) {
            throw new UnsupportedModeSINException('Solo modalidad XML (1) para sector educativo (11)');
            // $archivo = $this->buildJsonCompraVenta($args, $docSector);
            // $archivoBytes = json_encode($archivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                // Guardar copias indexadas del XML para acceso fácil (CUF y anio_nro)
                if ($xmlPath) {
                    $this->storeXmlIndexCopies($xmlPath, $args);
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
            // Guardar copia indexada del ZIP (CUF y anio_nro)
            $this->storeZipIndexCopy($archivoBytesForSoap, $xmlPath, $args);
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
            'codigoAmbiente' => (int) (isset($args['ambiente']) ? $args['ambiente'] : 2),
            'codigoModalidad' => $modalidad,
            'codigoEmision' => (int) (isset($args['tipo_emision']) ? $args['tipo_emision'] : 1),
            'nit' => (int) (isset($args['nit']) ? $args['nit'] : 0),
            'codigoSistema' => (string) (isset($args['cod_sistema']) ? $args['cod_sistema'] : ''),
            'codigoSucursal' => (int) (isset($args['sucursal']) ? $args['sucursal'] : 0),
            'codigoPuntoVenta' => (int) (isset($args['punto_venta']) ? $args['punto_venta'] : 0),
            'tipoFacturaDocumento' => (int) (isset($args['tipo_factura']) ? $args['tipo_factura'] : 1),
            'codigoDocumentoSector' => $docSector,
            'cuis' => (string) (isset($args['cuis']) ? $args['cuis'] : ''),
            'cufd' => (string) (isset($args['cufd']) ? $args['cufd'] : ''),
            'cuf' => (string) (isset($args['cuf']) ? $args['cuf'] : ''),
            'fechaEnvio' => $fechaEnvioIso,
            // Importante: el SoapClient PHP espera bytes para base64Binary y realiza el encoding internamente
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
    private function compressSingleGzToZip($xml, $xmlFilePath = null)
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
    private function saveXmlDebugCopy($xml, $args, $docSector)
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
    private function compressMultipleTarGz($xmlFiles)
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

    // private function buildJsonCompraVenta($args, $docSector)
    // {
    //     // Cabecera básica según Compra-Venta; adaptable a otros docSector cuando se requiera
    //     $fechaIso = Carbon::parse($args['fecha_emision'] ?? Carbon::now())->format('Y-m-d\TH:i:s.000');

    //     // Método de pago: mapear desde sin_forma_cobro por id_forma_cobro interno
    //     $codigoMetodoPago = 1; // Efectivo default
    //     if (!empty($args['id_forma_cobro'])) {
    //         $m = DB::table('sin_forma_cobro')->where('id_forma_cobro', (string)$args['id_forma_cobro'])->first();
    //         if ($m && isset($m->codigo_sin)) {
    //             $codigoMetodoPago = (int) $m->codigo_sin;
    //         }
    //     }

    //     // Actividad económica y leyenda asociada (aleatoria por actividad)
    //     $actividad = DB::table('sin_actividades')->value('codigo_caeb') ?: (string) config('sin.actividad_economica', '853000');
    //     $leyenda = DB::table('sin_list_leyenda_factura')
    //         ->where('codigo_actividad', $actividad)
    //         ->inRandomOrder()
    //         ->value('descripcion_leyenda');
    //     if (!$leyenda) {
    //         $leyenda = DB::table('sin_list_leyenda_factura')->value('descripcion_leyenda')
    //             ?: 'Ley N° 453: Esta factura contribuye al desarrollo del país.';
    //     }

    //     $razonEmisorCfg = (string) config('sin.razon_social', 'EMISOR');
    //     $municipioCfg = (string) config('sin.municipio', 'COCHABAMBA');
    //     $telefonoCfg = config('sin.telefono');
    //     $codigoMonedaCfg = (int) config('sin.codigo_moneda', 1);
    //     $tipoCambioCfg = (float) config('sin.tipo_cambio', 1);
    //     $sucursalArg = (int) ($args['sucursal'] ?? 0);
    //     $puntoVentaArg = (int) ($args['punto_venta'] ?? 0);
    //     $direccionVal = isset($args['direccion']) ? (string)$args['direccion'] : '';
    //     if ($direccionVal === '') {
    //         try {
    //             $dirRow = DB::table('sin_cufd')
    //                 ->where('codigo_sucursal', $sucursalArg)
    //                 ->where('codigo_punto_venta', $puntoVentaArg)
    //                 ->where('fecha_vigencia', '>', now())
    //                 ->orderBy('fecha_vigencia', 'desc')
    //                 ->first();
    //             if ($dirRow && isset($dirRow->direccion) && $dirRow->direccion !== '') {
    //                 $direccionVal = (string)$dirRow->direccion;
    //             }
    //         } catch (\Throwable $e) {}
    //     }
    //     if ($direccionVal === '') { $direccionVal = 'S/D'; }

    //     // numeroTarjeta: solo cuando el método es TARJETA (2)
    //     $numeroTarjeta = null;
    //     if ((int)$codigoMetodoPago === 2) {
    //         $nt = $args['numero_tarjeta'] ?? null;
    //         if (is_string($nt) || is_numeric($nt)) {
    //             $ntSan = preg_replace('/\D/', '', (string)$nt);
    //             $numeroTarjeta = $ntSan !== '' ? $ntSan : null;
    //         }
    //         // Fallback: si no llegó por args, intentar recuperar de nota_bancaria por nro_factura
    //         if ($numeroTarjeta === null) {
    //             $nf = isset($args['numero_factura']) ? (string)$args['numero_factura'] : '';
    //             if ($nf !== '') {
    //                 try {
    //                     $nb = DB::table('nota_bancaria')
    //                         ->where('nro_factura', (string)$nf)
    //                         ->orderBy('anio_deposito', 'desc')
    //                         ->orderBy('correlativo', 'desc')
    //                         ->first();
    //                     if ($nb && !empty($nb->nro_tarjeta)) {
    //                         $ntSan = preg_replace('/\D/', '', (string)$nb->nro_tarjeta);
    //                         $numeroTarjeta = $ntSan !== '' ? $ntSan : null;
    //                     }
    //                 } catch (\Throwable $e) {}
    //             }
    //         }
    //     }

    //     $cabecera = [
    //         'nitEmisor' => (int) ($args['nit'] ?? 0),
    //         'razonSocialEmisor' => (string) ($args['razon_emisor'] ?? $razonEmisorCfg),
    //         'municipio' => (string) ($args['municipio'] ?? $municipioCfg),
    //         'telefono' => isset($args['telefono']) ? (int)$args['telefono'] : (is_numeric($telefonoCfg) ? (int)$telefonoCfg : null),
    //         'numeroFactura' => (int) ($args['numero_factura'] ?? 0),
    //         'cuf' => (string) ($args['cuf'] ?? ''),
    //         'cufd' => (string) ($args['cufd'] ?? ''),
    //         'codigoSucursal' => $sucursalArg,
    //         'direccion' => $direccionVal,
    //         'codigoPuntoVenta' => $puntoVentaArg,
    //         'fechaEmision' => $fechaIso,
    //         'nombreRazonSocial' => (string) ($args['cliente']['razon'] ?? 'S/N'),
    //         'codigoTipoDocumentoIdentidad' => (int) ($args['cliente']['tipo_doc'] ?? 5),
    //         'numeroDocumento' => (string) ($args['cliente']['numero'] ?? '0'),
    //         'complemento' => $args['cliente']['complemento'] ?? null,
    //         'codigoCliente' => (string) ($args['cliente']['codigo'] ?? ($args['cliente']['numero'] ?? '0')),
    //         'codigoMetodoPago' => (int) $codigoMetodoPago,
    //         'numeroTarjeta' => $numeroTarjeta,
    //         'montoTotal' => (float) ($args['monto_total'] ?? 0),
    //         'montoTotalSujetoIva' => (float) ($args['monto_total'] ?? 0),
    //         'codigoMoneda' => $codigoMonedaCfg,
    //         'tipoCambio' => $tipoCambioCfg,
    //         'montoTotalMoneda' => (float) ($args['monto_total'] ?? 0),
    //         'leyenda' => $leyenda,
    //         'usuario' => (string) ($args['usuario'] ?? 'system'),
    //         'codigoDocumentoSector' => $docSector,
    //     ];

    //     // Detalle(s) para JSON: si viene 'detalles' usar lista; si no, usar 'detalle' único
    //     $detalle = [];
    //     if (!empty($args['detalles']) && is_array($args['detalles'])) {
    //         foreach ($args['detalles'] as $d) {
    //             $actEco = (string) ($actividadEnv = (string) config('sin.actividad_economica', $actividad));
    //             $codSin = (int) ($d['codigo_sin'] ?? ($args['detalle']['codigo_sin'] ?? (int) config('sin.codigo_producto_sin', 0)));
    //             $codProd = (string) ($d['codigo'] ?? ($args['detalle']['codigo'] ?? 'ITEM'));
    //             $desc = (string) ($d['descripcion'] ?? ($args['detalle']['descripcion'] ?? 'Servicio/Item'));
    //             $cant = (float) ($d['cantidad'] ?? ($args['detalle']['cantidad'] ?? 1));
    //             $uni = (int) ($d['unidad_medida'] ?? ($args['detalle']['unidad_medida'] ?? 1));
    //             $sub = (float) ($d['subtotal'] ?? ($args['detalle']['subtotal'] ?? ($args['monto_total'] ?? 0)));
    //             $descItem = (float) ($d['descuento'] ?? ($args['detalle']['descuento'] ?? 0));
    //             $puRaw = $d['precio_unitario'] ?? ($args['detalle']['precio_unitario'] ?? null);
    //             $pu = ($puRaw !== null)
    //                 ? (float) $puRaw
    //                 : ($cant > 0 ? (($sub + $descItem) / $cant) : ($sub + $descItem));
    //             $detalle[] = [
    //                 'actividadEconomica' => $actEco,
    //                 'codigoProductoSin' => $codSin,
    //                 'codigoProducto' => $codProd,
    //                 'descripcion' => $desc,
    //                 'cantidad' => $cant,
    //                 'unidadMedida' => $uni,
    //                 'precioUnitario' => (float) $pu,
    //                 'montoDescuento' => (float) $descItem,
    //                 'subTotal' => (float) $sub,
    //             ];
    //         }
    //     } else {
    //         $actEco = (string) ((string) config('sin.actividad_economica', $actividad));
    //         $codSin = (int) ($args['detalle']['codigo_sin'] ?? (int) config('sin.codigo_producto_sin', 0));
    //         $codProd = (string) ($args['detalle']['codigo'] ?? 'ITEM');
    //         $desc = (string) ($args['detalle']['descripcion'] ?? 'Servicio/Item');
    //         $cant = (float) ($args['detalle']['cantidad'] ?? 1);
    //         $sub = (float) ($args['detalle']['subtotal'] ?? ($args['monto_total'] ?? 0));
    //         $uni = (int) ($args['detalle']['unidad_medida'] ?? 1);
    //         $descItem = (float) ($args['detalle']['descuento'] ?? 0);
    //         $puRaw = $args['detalle']['precio_unitario'] ?? null;
    //         $pu = ($puRaw !== null)
    //             ? (float) $puRaw
    //             : ($cant > 0 ? (($sub + $descItem) / $cant) : ($sub + $descItem));
    //         $detalle = [[
    //             'actividadEconomica' => $actEco,
    //             'codigoProductoSin' => $codSin,
    //             'codigoProducto' => $codProd,
    //             'descripcion' => $desc,
    //             'cantidad' => $cant,
    //             'unidadMedida' => $uni,
    //             'precioUnitario' => (float) $pu,
    //             'montoDescuento' => (float) $descItem,
    //             'subTotal' => (float) $sub,
    //         ]];
    //     }

    //     return [
    //         'cabecera' => $cabecera,
    //         'detalle' => $detalle,
    //     ];
    // }

    private function buildXmlSectorEducativo($args, $docSector)
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
        $numeroTarjetaXml = null;
        if ((int)$codigoMetodoPago === 2) {
            $nt = $args['numero_tarjeta'] ?? null;
            Log::debug('FacturaPayloadBuilder.buildXmlSectorEducativo.numeroTarjetaInput', [
                'input' => $nt,
            ]);
            if (is_string($nt) || is_numeric($nt)) {
                $ntSan = preg_replace('/\D/', '', (string)$nt);
                if ($ntSan !== '') {
                    $numeroTarjetaXml = $ntSan;
                    /// colocar un log aqui
                    Log::debug('FacturaPayloadBuilder.buildXmlSectorEducativo.numeroTarjetaSanitized', [
                        'sanitized' => $numeroTarjetaXml,
                    ]);
                }
            }
            if ($numeroTarjetaXml === null) {
                $nf = isset($args['numero_factura']) ? (string)$args['numero_factura'] : '';
                Log::debug('FacturaPayloadBuilder.buildXmlSectorEducativo.numeroTarjetaFallbackFactura', [
                    'numero_factura' => $nf,
                ]);
                if ($nf !== '') {
                    try {
                        $nb = DB::table('nota_bancaria')
                            ->where('nro_factura', (string)$nf)
                            ->orderBy('anio_deposito', 'desc')
                            ->orderBy('correlativo', 'desc')
                            ->first();
                        if ($nb && !empty($nb->nro_tarjeta)) {
                            $ntSan = preg_replace('/\D/', '', (string)$nb->nro_tarjeta);
                            Log::debug('FacturaPayloadBuilder.buildXmlSectorEducativo.numeroTarjetaFallbackSanitized', [
                                'sanitized' => $ntSan,
                            ]);
                            if ($ntSan !== '') {
                                $numeroTarjetaXml = $ntSan;
                            }
                        }
                    } catch (\Throwable $e) {}
                }
            }
        }
        $actividad = DB::table('sin_actividades')->value('codigo_caeb') ?: '00000';
        $leyenda = DB::table('sin_list_leyenda_factura')
            ->where('codigo_actividad', $actividad)
            ->inRandomOrder()
            ->value('descripcion_leyenda')
            ?: (DB::table('sin_list_leyenda_factura')->value('descripcion_leyenda')
                ?: 'Ley N° 453: Esta factura contribuye al desarrollo del país.');

        // Cliente
        $cli = $args['cliente'] ?? [];
        $nombreEst = $args['nombre_estudiante'] ?? ($cli['razon'] ?? 'S/N');
        $periodo = $args['periodo_facturado'] ?? ($args['detalle']['periodo_facturado'] ?? null);

        // Detalle
        $det = $args['detalle'] ?? [];
        $codigoProductoSin = (int) ($det['codigo_sin'] ?? (int) config('sin.codigo_producto_sin', 99100));
        if ($codigoProductoSin <= 0) {
            $codigoProductoSin = (int) config('sin.codigo_producto_sin', 99100);
        }
        $unidadMedida = (int) ($det['unidad_medida'] ?? 1);
        $codigoProducto = (string) ($det['codigo'] ?? '123456');
        $descripcion = (string) ($det['descripcion'] ?? 'Servicio educativo');
        $cantidad = (float) ($det['cantidad'] ?? 1);
        $precioUnitario = (float) ($det['precio_unitario'] ?? ($args['monto_total'] ?? 0));
        $subTotal = (float) ($det['subtotal'] ?? ($args['monto_total'] ?? 0));

        // Suma de subtotales (netos) y totales de cabecera según lógica SGA
        $sumSub = 0.0;
        if (!empty($args['detalles']) && is_array($args['detalles'])) {
            foreach ($args['detalles'] as $dx) {
                $sumSub += (float) ($dx['subtotal'] ?? 0);
            }
        } else {
            $sumSub = (float) ($det['subtotal'] ?? ($args['monto_total'] ?? 0));
        }
        $descAdic = (float) ($args['descuento_adicional'] ?? ($args['descuentoAdicional'] ?? 0));
        $giftCard = (float) ($args['gift_card'] ?? ($args['monto_gift_card'] ?? ($args['giftCard'] ?? 0)));
        $montoTotalCalc = round($sumSub - $descAdic, 2);
        $montoTotalSujetoIvaCalc = round($montoTotalCalc - $giftCard, 2);
        $montoMonedaCalc = $montoTotalCalc;

        // Emisor (usar .env/config y direccion desde sin_cufd)
        $razonEmisorCfg = (string) config('sin.razon_social', 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L.');
        $municipioCfg = (string) config('sin.municipio', 'COCHABAMBA');
        $telefonoCfg = config('sin.telefono');
        $razonEmisor = (string) ($args['razon_emisor'] ?? $razonEmisorCfg);
        $municipio = (string) ($args['municipio'] ?? $municipioCfg);
        $telefono = isset($args['telefono']) ? (int)$args['telefono'] : (is_numeric($telefonoCfg) ? (int)$telefonoCfg : null);
        $direccion = isset($args['direccion']) ? (string)$args['direccion'] : '';
        if ($direccion === '') {
            try {
                $sucArg = (int) ($args['sucursal'] ?? 0);
                $pvArg = (int) ($args['punto_venta'] ?? 0);
                $dirRow = DB::table('sin_cufd')
                    ->where('codigo_sucursal', $sucArg)
                    ->where('codigo_punto_venta', $pvArg)
                    ->where('fecha_vigencia', '>', now())
                    ->orderBy('fecha_vigencia', 'desc')
                    ->first();
                if ($dirRow && isset($dirRow->direccion) && $dirRow->direccion !== '') {
                    $direccion = (string)$dirRow->direccion;
                }
            } catch (\Throwable $e) {}
        }
        if ($direccion === '') { $direccion = 'S/D'; }

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
        if ($numeroTarjetaXml !== null) {
            $xml .= '<numeroTarjeta>' . htmlspecialchars($numeroTarjetaXml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</numeroTarjeta>';
        } else {
            $xml .= '<numeroTarjeta xsi:nil="true"/>';
        }
        $xml .= '<montoTotal>' . number_format($montoTotalCalc, 2, '.', '') . '</montoTotal>';
        $xml .= '<montoTotalSujetoIva>' . number_format($montoTotalSujetoIvaCalc, 2, '.', '') . '</montoTotalSujetoIva>';
        $xml .= '<codigoMoneda>' . (int) config('sin.codigo_moneda', 1) . '</codigoMoneda>';
        $xml .= '<tipoCambio>' . number_format((float) config('sin.tipo_cambio', 1), 2, '.', '') . '</tipoCambio>';
        $xml .= '<montoTotalMoneda>' . number_format($montoMonedaCalc, 2, '.', '') . '</montoTotalMoneda>';
        if ($giftCard > 0) {
            $xml .= '<montoGiftCard>' . number_format($giftCard, 2, '.', '') . '</montoGiftCard>';
        } else {
            $xml .= '<montoGiftCard xsi:nil="true"/>';
        }
        if ($descAdic > 0) {
            $xml .= '<descuentoAdicional>' . number_format($descAdic, 2, '.', '') . '</descuentoAdicional>';
        } else {
            $xml .= '<descuentoAdicional xsi:nil="true"/>';
        }
        $xml .= '<codigoExcepcion xsi:nil="true"/>';
        $xml .= '<cafc xsi:nil="true"/>';
        $xml .= '<leyenda>' . htmlspecialchars($leyenda, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</leyenda>';
        $xml .= '<usuario>' . htmlspecialchars((string)($args['usuario'] ?? 'system'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</usuario>';
        $xml .= '<codigoDocumentoSector>' . (int)$docSector . '</codigoDocumentoSector>';
        $xml .= '</cabecera>';
        // Detalle(s) para XML: si viene 'detalles' usar lista repetida <detalle>...
        if (!empty($args['detalles']) && is_array($args['detalles'])) {
            foreach ($args['detalles'] as $d) {
                $xml .= '<detalle>';
                $xml .= '<actividadEconomica>' . htmlspecialchars((string)$actividad, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</actividadEconomica>';
                $xml .= '<codigoProductoSin>' . (int)($d['codigo_sin'] ?? $codigoProductoSin) . '</codigoProductoSin>';
                $xml .= '<codigoProducto>' . htmlspecialchars((string)($d['codigo'] ?? $codigoProducto), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</codigoProducto>';
                $xml .= '<descripcion>' . htmlspecialchars((string)($d['descripcion'] ?? $descripcion), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</descripcion>';
                $cantItem = (float)($d['cantidad'] ?? $cantidad);
                $subItem = (float)($d['subtotal'] ?? $subTotal);
                $descItem = (float)($d['descuento'] ?? 0);
                $puRaw = $d['precio_unitario'] ?? null;
                $puItem = ($puRaw !== null) ? (float)$puRaw : ($cantItem > 0 ? (($subItem + $descItem) / $cantItem) : ($subItem + $descItem));
                $xml .= '<cantidad>' . number_format($cantItem, 2, '.', '') . '</cantidad>';
                $xml .= '<unidadMedida>' . (int)($d['unidad_medida'] ?? $unidadMedida) . '</unidadMedida>';
                $xml .= '<precioUnitario>' . number_format($puItem, 2, '.', '') . '</precioUnitario>';
                if ($descItem > 0) {
                    $xml .= '<montoDescuento>' . number_format($descItem, 2, '.', '') . '</montoDescuento>';
                } else {
                    $xml .= '<montoDescuento xsi:nil="true"/>';
                }
                $xml .= '<subTotal>' . number_format($subItem, 2, '.', '') . '</subTotal>';
                $xml .= '</detalle>';
            }
        } else {
            $xml .= '<detalle>';
            $xml .= '<actividadEconomica>' . htmlspecialchars((string)$actividad, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</actividadEconomica>';
            $xml .= '<codigoProductoSin>' . (int)$codigoProductoSin . '</codigoProductoSin>';
            $xml .= '<codigoProducto>' . htmlspecialchars($codigoProducto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</codigoProducto>';
            $xml .= '<descripcion>' . htmlspecialchars($descripcion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</descripcion>';
            $cantItem = (float)$cantidad;
            $subItem = (float)$subTotal;
            $descItem = (float)($det['descuento'] ?? 0);
            $puRaw = $det['precio_unitario'] ?? null;
            $puItem = ($puRaw !== null) ? (float)$puRaw : ($cantItem > 0 ? (($subItem + $descItem) / $cantItem) : ($subItem + $descItem));
            $xml .= '<cantidad>' . number_format($cantItem, 2, '.', '') . '</cantidad>';
            $xml .= '<unidadMedida>' . (int)$unidadMedida . '</unidadMedida>';
            $xml .= '<precioUnitario>' . number_format($puItem, 2, '.', '') . '</precioUnitario>';
            if ($descItem > 0) {
                $xml .= '<montoDescuento>' . number_format($descItem, 2, '.', '') . '</montoDescuento>';
            } else {
                $xml .= '<montoDescuento xsi:nil="true"/>';
            }
            $xml .= '<subTotal>' . number_format($subItem, 2, '.', '') . '</subTotal>';
            $xml .= '</detalle>';
        }
        $xml .= '</facturaElectronicaSectorEducativo>';
        return $xml;
    }

    private function storeXmlIndexCopies($xmlPath, $args)
    {
        try {
            $anio = null;
            try { $anio = (int) date('Y', strtotime((string)($args['fecha_emision'] ?? ''))); } catch (\Throwable $e) { $anio = (int) date('Y'); }
            if ($anio <= 0) { $anio = (int) date('Y'); }
            $nro = (int) ($args['numero_factura'] ?? 0);
            $cuf = (string) ($args['cuf'] ?? '');
            $safeCuf = $cuf !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $cuf) : '';

            $dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'index');
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if ($nro > 0) {
                @copy($xmlPath, $dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '.xml');
            }
            if ($safeCuf !== '') {
                @copy($xmlPath, $dir . DIRECTORY_SEPARATOR . $safeCuf . '.xml');
            }
        } catch (\Throwable $e) {
            Log::warning('FacturaPayloadBuilder.storeXmlIndexCopies error', [ 'error' => $e->getMessage() ]);
        }
    }

    private function storeZipIndexCopy($zipBytes, $xmlPath, $args)
    {
        try {
            $anio = null;
            try { $anio = (int) date('Y', strtotime((string)($args['fecha_emision'] ?? ''))); } catch (\Throwable $e) { $anio = (int) date('Y'); }
            if ($anio <= 0) { $anio = (int) date('Y'); }
            $nro = (int) ($args['numero_factura'] ?? 0);
            $cuf = (string) ($args['cuf'] ?? '');
            $safeCuf = $cuf !== '' ? preg_replace('/[^A-Za-z0-9]/', '', $cuf) : '';

            $dir = storage_path('siat_xml' . DIRECTORY_SEPARATOR . 'comprimidos' . DIRECTORY_SEPARATOR . 'index');
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            if ($nro > 0) {
                @file_put_contents($dir . DIRECTORY_SEPARATOR . $anio . '_' . $nro . '.zip', $zipBytes);
            }
            if ($safeCuf !== '') {
                @file_put_contents($dir . DIRECTORY_SEPARATOR . $safeCuf . '.zip', $zipBytes);
            }
        } catch (\Throwable $e) {
            Log::warning('FacturaPayloadBuilder.storeZipIndexCopy error', [ 'error' => $e->getMessage() ]);
        }
    }

}
