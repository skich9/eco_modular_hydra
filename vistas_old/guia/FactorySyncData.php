<?php

require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/Activity.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/DataTime.php');

require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/list_data/activityDocument.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/list_data/LeyentBill.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/list_data/activityDocument.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/list_data/MessageService.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/list_data/ProcuctService.php');

require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/OriginContry.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/SignificantEvents.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/ReasonForCancellation.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeDocumenteIdentity.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeDocuementSeptor.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeEmition.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeMetodPay.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeCoin.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypePointSale.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeBill.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeUnitMeasuremen.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/TypeRoom.php');
// require_once(str_replace("\\","/",APPPATH).'core/service_bill/sync_data/parametric/ProcuctService.php');

class FactorySyncData{
    
    private $config = [];
    private static $instance;

    private function __construct($config){
        $this->config = $config;
    }

    public static function getInstance($config){
        if(!self::$instance instanceof self){
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function sinc_actividades($cuis, $codPuntoVenta=null) {
        $actividades = new Activity($this->config);
        return $actividades->sincrinizarActividad($cuis, $codPuntoVenta);
    }
    public function sinc_fecha_hora($cuis, $codPuntoVenta=null) {
        $actividades = new DataTime($this->config);
        return $actividades->sincronizarFechaHora($cuis, $codPuntoVenta);
    }
    public function sinc_list_Actividades_documento_sector($cuis, $codPuntoVenta=null) {
        $actividades = new ActivityDocument($this->config);
        return $actividades->sincronizarListaActividadesDocumentoSector($cuis, $codPuntoVenta);
    }
    
    public function sinc_list_leyendas_factura($cuis, $codPuntoVenta=null) {
        $actividades = new LeyentBill($this->config);
        return $actividades->sincronizarListaLeyendasFactura($cuis, $codPuntoVenta);
    }

    public function sinc_list_mensaje_service($cuis, $codPuntoVenta=null) {
        $actividades = new MessageService($this->config);
        return $actividades->sincronizarListaMensajesServicios($cuis, $codPuntoVenta);
    }

    public function sinc_list_productos_servicios($cuis, $codPuntoVenta=null) {
        $actividades = new ProcuctService($this->config);
        return $actividades->sincronizarListaProductosServicios($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_origin_country($cuis, $codPuntoVenta=null) {
        $actividades = new OriginContry($this->config);
        return $actividades->sincronizarParametricaPaisOrigen($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_evento_significativo($cuis, $codPuntoVenta=null) {
        $actividades = new SignificantEvents($this->config);
        return $actividades->significantEvents($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_motivo_anulacion($cuis, $codPuntoVenta=null) {
        $actividades = new ReasonForCancellation($this->config);
        return $actividades->sincronizarParametricaMotivoAnulacion($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_documento_identidad($cuis, $codPuntoVenta=null) {
        $actividades = new TypeDocumenteIdentity($this->config);
        return $actividades->sincronizarParametricaTipoDocumentoIdentidad($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_documento_sector($cuis, $codPuntoVenta=null) {
        $actividades = new typeDocuementSeptor($this->config);
        return $actividades->sincronizarParametricaTipoDocumentoSector($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_emision($cuis, $codPuntoVenta=null) {
        $actividades = new TypeEmition($this->config);
        return $actividades->sincronizarParametricaTipoEmision($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_metodo_pago($cuis, $codPuntoVenta=null) {
        $actividades = new TypeMetodPay($this->config);
        return $actividades->sincronizarParametricaTipoMetodoPago($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_moneda($cuis, $codPuntoVenta=null) {
        $actividades = new TypeCoin($this->config);
        return $actividades->sincronizarParametricaTipoMoneda($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_punto_venta($cuis, $codPuntoVenta=null) {
        $actividades = new TypePointSale($this->config);
        return $actividades->sincronizarParametricaTipoPuntoVenta($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_factura($cuis, $codPuntoVenta=null) {
        $actividades = new TypeBill($this->config);
        return $actividades->sincronizartypeBill($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_unidad_medida($cuis, $codPuntoVenta=null) {
        $actividades = new TypeUnitMeasuremen($this->config);
        return $actividades->sincronizarParametricaUnidadMedida($cuis, $codPuntoVenta);
    }

    public function sinc_parametric_tipo_habitacion($cuis, $codPuntoVenta=null) {
        $actividades = new TypeRoom($this->config);
        return $actividades->sincronizarParametricaTipoHabitacion($cuis, $codPuntoVenta);
    }
}
?>