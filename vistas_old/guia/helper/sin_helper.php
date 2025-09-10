<?php
include('Math/BigInteger.php');

function relleno_de_cero_tarjetas($primeros_4, $ultimos_4){
    return $primeros_4."00000000".$ultimos_4;
}



function getParametrosWS($controller) {
    //debug_print_backtrace();
    $response = array(
        'URLPATH'=>$controller->config->item(URL_IMPUESTOS_SIAT),
        'CODAMBIENTE'=>$controller->config->item(CODIGO_AMBIENTE_SGA),
        'MODALIDAD'=>$controller->config->item(MODALIDAD_SGA),
        'CODSISTEMA' => $controller->config->item(CODIGO_SISTEMA_SGA),
        'NITSGA'=>$controller->config->item(NIT_SGA),
        'SUCURSALSGA'=>$controller->config->item(SUCURSAL_SGA),
        'SERVSINCRONIZACIONDATOS'=>$controller->config->item(SINCRONIZACION_DE_DATOS),
        'SERVRECEPCIONCOMPRAS'=>$controller->config->item(RECEPCION_DE_COMPRAS),
        'SERVOPERACIONES'=>$controller->config->item(SERVICIO_DE_OPERACIONES),
        'SERVOBTENCIOCODE'=>$controller->config->item(SERVICIO_OBTENCIÓN_DE_CODIGOS),
        'SERVFACTURACIONELEC'=>$controller->config->item(SERVICIO_FACTURACION_ELECTRONICA),
        'TIPODOCUMENTOSECTOREDUCATIVO' => $controller->config->item(TIPO_FACTURA),
        'CODIGODOCUMENTOSECTOR' => $controller->config->item(COD_DOC_SECTORIAL),
        'LEYENDAOFFLINE' => $controller->config->item(LEYENDA_OFF_LINE),
        'TELEFONO' => $controller->config->item(TELEFONO)
    );
    return $response;
}

function getArrDatosConst($controller){
    $response = array(
        'NITSGA'=>$controller->config->item(NIT_SGA),
        'RAZONSOCIALEMISOR'=>$controller->config->item(RAZONSOCIAL_EMISOR),
        'MUNICIPIO'=>$controller->config->item(MUNICIPIO),
        'TELEFONO'=>$controller->config->item(TELEFONO)
    );
    return $response;
}

function diferencia_fechas($fecha_factura, $fecha_actual){
    $fecha_factura = strtotime(date($fecha_factura));
    $fecha_actual = strtotime(date($fecha_actual));
    $dias = ($fecha_actual - $fecha_factura) / 3600;
    $dias = round($dias, 2);
    // $dias = str_replace(".", ":", $dias);
    return   $dias;
}
function completeCero($pString,  $pMaxChar, $pRigth = false){
    $vNewString = $pString;
    if (strlen($pString) < $pMaxChar)
    {
        for ($i = strlen($pString); $i < $pMaxChar; $i++)
        {
            // $vNewString = string.Concat("0", vNewString);
            $vNewString = "0".$vNewString;
        }
    }
    return $vNewString;
}

// resive enteros como cadena y devuelve enteros pero son la representacion en base16 del entero original
function Base16($pString) {
    $res = new Math_BigInteger($pString);
    return $res->toHex();
}

function Base10($pString) {
    $res = new Math_BigInteger($pString,16);
    return $res->toString();
}

function calculoDigitoMod11($cadena, $numDig = 1, $limMult = 9, $x10 = false) {
    $suma = 0;
    $mult = 0;
    $i = 0;
    $n = 0;
    $dig = 0;

    if (!$x10) {
        $numDig = 1;
    }
    //echo "El numDig que se maneja es:" . $numDig;
    //echo "==><br>";
    // que valor tei
    for ($n = 1; $n <= $numDig; $n++) {
        $suma = 0;
        $mult = 2;
        //$thans = strlen($cadena);
        for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
            //echo "El i que se maneja es::{$i}>><br>";

            //$masuno = $i + 1;
            $in = intval(substr($cadena, $i, 1));
            //echo "res->substring {$in}  masuno {$masuno}>><br>";
            $suma += ($mult * $in);
            //echo "la suma es {$suma}==> el mult es{$mult}<br>";
            if (++$mult > $limMult) {
                $mult = 2;
            }
        }
        if ($x10) {
            $dig = (($suma * 10) % 11) % 10;
            //echo "1: $dig <br>";
        } else {
            $dig = $suma % 11;
            //echo "2: $dig <br>";
        }
        if ($dig == 10) {
            $cadena .= "1";
            //echo "3: $dig <br>";
        }
        if ($dig == 11) {
            $cadena .= "0";
            //echo "4: $dig <br>";
        }
        if ($dig < 10) {
            $cadena .= strval($dig);
            //echo "5: $dig <br>";
        }
        //echo "la cadena resultante es:" . $cadena;
    }
    return substr($cadena, strlen($cadena) - $numDig, strlen($cadena));
}
    //generacion de codigo Cuf
function generationCodeCuf($controller, $fecha, $tipoEmision, $tipDocAjustada, $tipoDocSector, $numFactura, $puntoVenta, $codControlCufd){
    //se rellenan la varoiables con 0 determinados para cada parametro de SIAT
    $NITEMISOR=str_pad($controller->config->item(NIT_SGA), 13, '0', STR_PAD_LEFT);
    $SUCURSAL=str_pad($controller->config->item(SUCURSAL_SGA), 4, '0', STR_PAD_LEFT);
    $FECHAHORA=$fecha;
    $MODALIDAD=$controller->config->item(MODALIDAD_SGA);
    $TIPOEMISION=$tipoEmision;               /// debe cambiar de acuerdo a donde se realiza la llamada
    $TIPOFACTURA_DOCUMENTOAJUSTE=$tipDocAjustada;
    //>>>TODO: definir tipo de documento sector 
    $TIPODOCUMENTOSECTOR=str_pad($tipoDocSector, 2, '0', STR_PAD_LEFT);
    $NUMEROFACTURA=str_pad($numFactura, 10, '0', STR_PAD_LEFT);
    $PUNTODEVENTA=str_pad($puntoVenta, 4, '0', STR_PAD_LEFT);
    //se concatena segun lo que indica SIAT
    log_message('error','los parmetos que se usan para genera el cuf son NITEMISOR:'.$NITEMISOR.'==>FECHAHORA:'.$FECHAHORA.'==>SUCURSAL:'.$SUCURSAL
        ."==>MODALIDAD:".$MODALIDAD."==>TIPO EMISION".$TIPOEMISION.'==>TIPO FACTURA:'.$TIPOFACTURA_DOCUMENTOAJUSTE.'==>TIPO DOC SECTOR:'.$TIPODOCUMENTOSECTOR
        ."==>NUM FACTURA".$NUMEROFACTURA."==>PUNTOVENTA".$PUNTODEVENTA);
    $concat=$NITEMISOR.$FECHAHORA.$SUCURSAL.$MODALIDAD.$TIPOEMISION.$TIPOFACTURA_DOCUMENTOAJUSTE.$TIPODOCUMENTOSECTOR.$NUMEROFACTURA.$PUNTODEVENTA;
    log_message('error','El concat es:'.$concat);

    $cant_letras = strlen($concat);
    if($cant_letras != 53) {
        throw new Exception("Error al generar el CUF por favor contacte con el administrador del sistema.".$cant_letras);
    }

    $CODIGOAUTOVERIFICADOR=calculoDigitoMod11($concat);
    $concat_mod11=$concat.$CODIGOAUTOVERIFICADOR;

    $base16=strtoupper(Base16($concat_mod11));
    
    $concat_Base16codControlCufd=$base16.$codControlCufd;
    //cudf generado 
    return  $concat_Base16codControlCufd;
}

function shash256($numeroFactura,$extension = '.zip'){
    $test_file = str_replace("\\","/",APPPATH) . "../plantilla/plantillas/impuestos/comprimidos/".$numeroFactura.$extension;
    //$test_file_read = file_get_contents($test_file);
    $test_file_hash = hash_file("sha256", $test_file, false);
    // print("File Hash ($test_file_read): $test_file_hash");
    return $test_file_hash;
}

/// hay que pasar el ciqrcode como parametro, desde el controlador donde se utilice
/// de debe cargar la libreria con la siguiente instucion en el contructor $this->load->library('qrcode/ciqrcode');
function generar_qr($urlQR, $ciqrcode, $valorNit, $valorCuf, $valorNroFactura, $valorTamaño) {
    $params['data'] = $urlQR."QR?nit={$valorNit}&cuf={$valorCuf}&numero=$valorNroFactura&t=$valorTamaño";
    $params['level'] = 'H';
    $params['size'] = 2;

    $params['savename'] = str_replace("\\", "/", APPPATH) . "../plantilla/plantillas/impuestos/qrcode/{$valorNroFactura}_{$valorTamaño}_qr.png" ;
    $qr = $ciqrcode->generate($params);
    //// devuelve la url donde se genero el qr
    $rutaArchivo = str_replace("\\", "/", APPPATH) . "../plantilla/plantillas/impuestos/qrcode/{$valorNroFactura}_{$valorTamaño}_qr.png";
    // log_message("error", "la ruta es ".print_r($rutaArchivo));
    return str_replace("\\", "/", APPPATH) . "../plantilla/plantillas/impuestos/qrcode/{$valorNroFactura}_{$valorTamaño}_qr.png";
    //echo "este el qr".$qr;
}

function fechaActual() {
    $ahora = DateTime::createFromFormat('U.u', number_format(microtime(true), 2, '.', ''));
    $fechaActual = $ahora->format("Y-m-d H:i:s.u");
    $fechaActual = substr($fechaActual, 0, 22);
    return $fechaActual;
}

function fechaFormatoUTC(){
    $fechaHora = date('Y-m-d\TH:i:s.000');
    return $fechaHora;
}

function fechaFormatCuf(){
    $fechaHora=date('YmdHis000');
    return $fechaHora;
}

// function getFormatoCuf() {
//     return "YmdHis000";
// }

function getGestion(){
    $year = date('Y');
    $mes = (int)date('m');
    $periodo = "2/".$year;
    if($mes > 0 && $mes <= 6){
        $periodo="1/".$year;
    }
    log_message("error", "error de la optencion de gestion".$periodo);
    return $periodo;
}

function dep($data)
{
    $format  = print_r('<pre>');
    $format .= print_r($data);
    $format .= print_r('</pre>');
    return $format;
}

function generar_factura($cod_ceta, $usuario, $fecha, $mpdf, $factory, $controller, $tipo_factura_pdf, $ci_qr_code, $header, $lista_detalle
    , $num_factura, $nombre_archivo, $is_online, $leyenda_2 = "", $anular="") {
    $url_factura = "";
    log_message("error", "elementos del header : ".  print_r($header, true));
    log_message("error", "el usuario es :".$usuario);
    log_message("error", "la fecha es : ".$fecha);
    if($is_online || $header['tipo_emision'] == 1) {
        $leyenda_2 = '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”';
    } else {
        $leyenda_2 = $controller->config->item(LEYENDA_OFF_LINE);
    }
    log_message("error", "leyenda ".print_r($leyenda_2, true));
    if($tipo_factura_pdf == 1) {
        $url_qr = generar_qr($controller->config->item(URL_FACTURA),$ci_qr_code, $controller->config->item(NIT_SGA), $header['cuf'], $num_factura, 1);
        $num_to_text = num_to_letras($header['montoTotalSujetoIva'], "Bolivianos", "Bs.");
        log_message('error','Factura Rollo url qr es:'.$url_qr.'===>num_to_text:'.$num_to_text."==>headercuf".$header['cuf']);
        $url_factura = $factory->facturaRolloSin($cod_ceta,$usuario, $fecha, $mpdf, $header, $lista_detalle, $url_qr, $num_to_text, $nombre_archivo, $leyenda_2, $anular);
        // $url_factura = FactoryFactura::instance(getParametrosWS($controller))->facturaRolloSin($header, $lista_detalle, $url_qr, $num_to_text, $nombre_archivo);
    }
    if($tipo_factura_pdf == 2) {
        $url_qr = generar_qr($controller->config->item(URL_FACTURA),$ci_qr_code, $controller->config->item(NIT_SGA), $header['cuf'], $num_factura, 2);
        $num_to_text = num_to_letras($header['montoTotalSujetoIva'], "Bolivianos", "Bs.");
        log_message('error','Factura Cart url qr es:'.$url_qr.'===>num_to_text:'.$num_to_text."==>headercuf".$header['cuf']);
        $url_factura = $factory->facturaNormalSin($mpdf,$header, $lista_detalle, $url_qr, $num_to_text, $nombre_archivo, $leyenda_2, $anular);
        // $url_factura = FactoryFactura::instance(getParametrosWS($controller))->facturaNormalSin($header, $lista_detalle, $url_qr, $num_to_text, $nombre_archivo);
    }
    return $url_factura;
}

?>
