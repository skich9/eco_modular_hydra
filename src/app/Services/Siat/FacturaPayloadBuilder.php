<?php

namespace App\Services\Siat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FacturaPayloadBuilder
{
    public function buildRecepcionFacturaPayload(array $args): array
    {
        $now = Carbon::now('America/La_Paz');
        $modalidad = (int) ($args['modalidad'] ?? 1);
        $docSector = (int) ($args['doc_sector'] ?? 11);

        if ($modalidad === 2 && $docSector !== 11) {
            // JSON computarizada (para sectores distintos a educativo)
            $archivo = $this->buildJsonCompraVenta($args, $docSector);
            $archivoBytes = json_encode($archivo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // XML para modalidad 1 o sector educativo (11)
            $archivoBytes = $this->buildXmlSectorEducativo($args, $docSector);
        }

        // Comprimir (GZIP) y calcular hash sobre bytes comprimidos
        $archivoZip = function_exists('gzencode') ? gzencode($archivoBytes, 9) : $archivoBytes;
        $archivoB64 = base64_encode($archivoZip);
        $hashArchivo = hash('sha256', $archivoZip);

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
            'archivo' => $archivoB64,
            'hashArchivo' => $hashArchivo,
        ];

        Log::debug('FacturaPayloadBuilder.buildRecepcionFacturaPayload', [ 'lenArchivo' => strlen($archivoB64), 'modalidad' => $modalidad ]);
        return $payload;
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
        $xml .= '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<facturaComputarizadaSectorEducativo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="facturaComputarizadaSectorEducativo.xsd">';
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
        $xml .= ($periodo ? ('<periodoFacturado>' . htmlspecialchars((string)$periodo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</periodoFacturado>') : '<periodoFacturado xsi:nil="true"/>');
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
        $xml .= '</facturaComputarizadaSectorEducativo>';
        return $xml;
    }
}
