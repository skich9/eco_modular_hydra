<?php
 require_once(str_replace("\\","/",APPPATH).'core/service_bill/header/Header.php');
 require_once(str_replace("\\","/",APPPATH).'libraries/nusoap/nusoap.php');
 require_once(str_replace("\\","/",APPPATH).'core/service_bill/Sin.php');
class Cuis extends Sin{
    private $Header=[];
    private $metodo='cuis';
    private $urlPath;
    private $urlService;
    //atributos
    private $codAmbiente;
    private $codSistema;
    private $codeSucursal;
    private $cuis;
    private $nit;
    private $codPuntoVenta;
    private $modalidad;
    private $SolicitudSincronizacion='SolicitudCuis';

    function __construct(){
        parent::__construct();
        $cabecera = new Header();
        $this->Header=$cabecera->getHeader();
    }
    
    public function obtenercuis($config,$puntoVenta=null) {
        //recuperando datos del config
        $this->codPuntoVenta=$puntoVenta;
        $this->urlPath=$config['URLPATH'];
        $this->urlService =$config['SERVOBTENCIOCODE'];
    
        $this->codAmbiente=$config['CODAMBIENTE'];
        $this->codSistema=$config['CODSISTEMA'];
        $this->codeSucursal=$config['SUCURSALSGA'];
        $this->nit=$config['NITSGA'];
        $this->modalidad=$config['MODALIDAD'];
        //se concatena url padre con servicio url 
        $urlSIAT=$this->urlPath.$this->urlService.'?wsdl';
        //dtos en arreglos
        $arrData=array('codigoAmbiente'=>$this->codAmbiente,
                        'codigoModalidad'=>$this->modalidad,
                        'codigoPuntoVenta'=>$this->codPuntoVenta,
                        'codigoSistema'=>$this->codSistema,
                        'codigoSucursal'=>$this->codeSucursal,
                        'nit'=>$this->nit);

        $client = new nusoap_client($urlSIAT,'wsdl');
        $client->soap_defencoding = 'UTF-8';
		$client->decode_utf8 = false;
        
        $client->setCurlOption(CURLOPT_HTTPHEADER, $this->Header);
        $err = $client->getError();
        ob_start();
        var_dump($err);
        $debug_dump = ob_get_clean();
        log_message("error", "Cuando no hay conexion ver error>".$debug_dump);
        if ($err) {
            echo '<h2>Constructor error XX</h2>' . $err;
            exit();
        }
        if($this->offline){
            throw new Exception("Error de conexion endpoint obtenercuis");
        }
        $result1 = $client->call($this->metodo,array($this->SolicitudSincronizacion=>$arrData));
        if(!$result1){
            throw new Exception("Error de conexion endpoint OBTENCION DE CODIGOS Obtener CUIS");
        }
        if(array_key_exists("faultcode", $result1)) {
            $result1["mensaje"] = $result1["faultstring"];
            unset($client);
            throw new Exception($result1["faultstring"]);
            // return $result1;
        }

        if((array_key_exists("transaccion", $result1["RespuestaCuis"]) && $result1["RespuestaCuis"]["transaccion"] == "false" && $result1["RespuestaCuis"]["mensajesList"]["descripcion"]!="EXISTE UN CUIS VIGENTE PARA LA SUCURSAL O PUNTO DE VENTA")) {
            $result1["RespuestaCuis"]["mensaje"] = $result1["RespuestaCuis"]["mensajesList"]["descripcion"];
            unset($client);
            return $result1;
        }
        if((array_key_exists("transaccion", $result1["RespuestaCuis"]) && $result1["RespuestaCuis"]["transaccion"] == "false" && $result1["RespuestaCuis"]["mensajesList"]["descripcion"]=="EXISTE UN CUIS VIGENTE PARA LA SUCURSAL O PUNTO DE VENTA")) {
            $result1["RespuestaCuis"]["mensaje"] ="CUIS VIGENTE";
            unset($client);
            return $result1;
        }
        $result1["RespuestaCuis"]["mensaje"] = "exito";
        unset($client);
        return  $result1;
    }
}
?>