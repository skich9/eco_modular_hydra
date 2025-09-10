<?php
    require_once(str_replace("\\","/",APPPATH).'core/service_bill/header/Header.php');
    require_once(str_replace("\\","/",APPPATH).'libraries/nusoap/nusoap.php');
    require_once(str_replace("\\","/",APPPATH).'core/service_bill/Sin.php');
    class Cuefd extends Sin{
        private $Header=[];
        private $metodo='cufd';
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
        private $SolicitudSincronizacion='SolicitudCufd';
        function __construct(){
            parent::__construct();
            $cabecera = new Header();
            $this->Header=$cabecera->getHeader();
        }
        public function obtenerCufd($config,$cuis,$puntoVenta=null) {
            //recuperando datos del config
            $this->codPuntoVenta=$puntoVenta;
            $this->urlPath=$config['URLPATH'];
            $this->urlService =$config['SERVOBTENCIOCODE'];
        
            $this->codAmbiente=$config['CODAMBIENTE'];
            $this->codSistema=$config['CODSISTEMA'];
            $this->codeSucursal=$config['SUCURSALSGA'];
            $this->cuis = $cuis;
            $this->nit = $config['NITSGA'];
            $this->modalidad = $config['MODALIDAD'];
            //se concatena url padre con servicio url 
            $urlSIAT=$this->urlPath.$this->urlService.'?wsdl';
            //dtos en arreglos
            $arrData=array('codigoAmbiente'=>$this->codAmbiente,
                            'codigoModalidad'=>$this->modalidad,
                            'codigoPuntoVenta'=>$this->codPuntoVenta,
                            'codigoSistema'=>$this->codSistema,
                            'codigoSucursal'=>$this->codeSucursal,
                            'cuis'=>$this->cuis,
                            'nit'=>$this->nit);

            $client = new nusoap_client($urlSIAT,'wsdl');
            $client->soap_defencoding = 'UTF-8';
		    $client->decode_utf8 = false;

            $client->setCurlOption(CURLOPT_HTTPHEADER, $this->Header);
            $err = $client->getError();
            if ($err) {
                echo '<h2>Constructor error</h2>' . $err;
                exit();
            }
            if($this->offline){
                throw new Exception("El servidor de impuestos no responde, no se puede recuperar un nuevo CUFD");
            }
            /// AQUI HAY QUE MOSTRR EL VALOR DEL SEGUNDO PARAMETRO QUE SE MANDA, PARA ENCONTRAR EL ERROR
            log_message('error','la peticion cufd es:'.print_r(array($this->SolicitudSincronizacion=>$arrData),true));
            $result1 = $client->call($this->metodo,array($this->SolicitudSincronizacion=>$arrData));
            if ($client->fault) {
                log_message('error','sucedio un errror al realizar el request del cufd:'.$client->getError());
                throw new Exception("Error de conexion endpoint OBTENCION DE CODIGOS Obtener CUFD1");
            }
            if(!$result1) {
                throw new Exception("Error de conexion endpoint OBTENCION DE CODIGOS Obtener CUFD2");
            }
            log_message('error','GENERANDO CUFD NUEVO RESULT===>'.print_r($result1,true));
            if(array_key_exists("faultcode", $result1)) {
                $result1["mensaje"] = $result1["faultstring"];
                unset($client);
                throw new Exception($result1["faultstring"]);
            }

            if((array_key_exists("transaccion", $result1["RespuestaCufd"]) && $result1["RespuestaCufd"]["transaccion"] == "false" )) {
                $result1["RespuestaCufd"]["mensaje"] = $result1["RespuestaCufd"]["mensajesList"]["descripcion"];
                unset($client);
                return $result1;
            }
            $result1["RespuestaCufd"]["mensaje"] = "exito";
            unset($client);
            return  $result1;
        }
    }
?>