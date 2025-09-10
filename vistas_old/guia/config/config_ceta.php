<?php

$config['leyenda_off_line'] = '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido fuera de línea, verifique su envío con su proveedor o en la página web www.impuestos.gob.bo”';

define('LEYENDA_OFF_LINE', 'leyenda_off_line');

$config['nombre_instituto_completa'] = 'Instituto Tecnológico de Enseñanza Automotriz "CETA"';
define('NOMINACION_CETA_COMPLETA', 'nombre_instituto_completa');

$config['nombre_instituto'] = 'Instituto Tecnológico de Enseñanza Automotriz';
define('NOMINACION_CETA', 'nombre_instituto');

$config['nombre_instituto_mayuscula'] = 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ';
define('NOMINACION_CETA_MAY', 'nombre_instituto_mayuscula');

$config['nombre_instituto_mayuscula_completo'] = 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA"';
define('NOMINACION_CETA_MAY_COMPLETO', 'nombre_instituto_mayuscula_completo');

//variables de uso global del siat
$config['url_siat'] = 'https://siatrest.impuestos.gob.bo/v2/';
define('URL_IMPUESTOS_SIAT', 'url_siat');

$config['url_siat_factura'] = 'https://siat.impuestos.gob.bo/consulta/';
define('URL_FACTURA', 'url_siat_factura');

$config['modalidad'] = 1;
define('MODALIDAD_SGA', 'modalidad');

$config['codigo_sistema'] = '725C4103C19E7192A3EB35E';
define('CODIGO_SISTEMA_SGA', 'codigo_sistema');

$config['nit_entidad'] = 388386029;
define('NIT_SGA', 'nit_entidad');

//configuracion de variables para xml
$config['razon_social_emisor'] = 'INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L.';
define('RAZONSOCIAL_EMISOR', 'razon_social_emisor');

$config['municipio'] = 'COCHABAMBA';
define('MUNICIPIO', 'municipio');

$config['telefono'] = '4581736';
define('TELEFONO', 'telefono');

$config['actividad_economica'] = '853000';
define('ACTIVIDAD_ECONOMICA_SGA', 'actividad_economica');

$config['codigo_producto_sin'] = 99100;
define('CODIGO_PRODUCTO_SIN_SGA', 'codigo_producto_sin');

$config['codigo_documento_sector'] = 11;
define('CODIGO_DOCUMENTO_SECTOR_SGA', 'codigo_documento_sector');

$config['codigo_moneda'] = 1;//PAGO CON MONEDA BOLIVIANA
define('CODIGO_TIPO_MONEDA', 'codigo_moneda');


$config['tipo_cambio'] = 1;//PAGO CON MONEDA BOLIVIANA
define('TIPO_CAMBIO', 'tipo_cambio');


//servicios

$config['servicio_sincronizacion'] = 'FacturacionSincronizacion';
define('SINCRONIZACION_DE_DATOS', 'servicio_sincronizacion');

$config['servicio_compras'] = 'ServicioRecepcionCompras';
define('RECEPCION_DE_COMPRAS', 'servicio_compras');

$config['servicio_operaciones'] = 'FacturacionOperaciones';
define('SERVICIO_DE_OPERACIONES', 'servicio_operaciones');

$config['servicio_obt_codigos'] = 'FacturacionCodigos';
define('SERVICIO_OBTENCIÓN_DE_CODIGOS', 'servicio_obt_codigos');

$config['servicio_facturacion_electronica'] = 'ServicioFacturacionElectronica';
define('SERVICIO_FACTURACION_ELECTRONICA', 'servicio_facturacion_electronica');

//ruta para generar codigo qr
$config['url_qr'] = 'https://siat.impuestos.gob.bo/consulta/QR?';
define('URL_QR_SIN', 'url_qr');

$config['cod_doc_sectorial'] = 11;
define('COD_DOC_SECTORIAL', 'cod_doc_sectorial');

$config['tipo_factura'] = 1;
define('TIPO_FACTURA', 'tipo_factura');

/// para poder usa esta configuracion es necesario agregar la siguiente instruccion seria recomendable usarlo en el constructor
/// $this->config->load('config_ceta');

/// para poder llamar el nombre de la institucion es necesario hacerlo de la siguiente manera
/// $value = $this->config->item(NOMINACION_CETA);
/// $value = $this->config->item(NOMINACION_CETA_COMPLETA);
/// $value = $this->config->item(NOMINACION_CETA_MAY);
/// $value = $this->config->item(NOMINACION_CETA_MAY_COMPLETO);
