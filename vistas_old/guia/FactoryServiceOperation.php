<?php
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/CloseOperationSystem.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/ClosePointSaler.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/QueryEvent.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/QueryPointSaler.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/RegisterEvent.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/RegisterPointSaler.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/RegisterPointSalerCommissionAgent.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/VerifyComunication.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_operation/ConextionOperation.php');

    class FactoryServiceOperation {
        private $config = [];
        private static $instance;
        
        private $cuis;
        private $puntVenta;
        private $cufd;
        private $fechaEvent;
        private $fechaEventoIni;
        private $fechaEventoFin;
        private $cufdEvent;
        private $description;
        private $codEvent;
        private $codTipoPuntVenta;
        private $nombrePuntVenta;
        
        private function __construct($config)
        {
            $this->config = $config;
        }

        public static function getInstance($config) {
            if (!self::$instance instanceof self) {
                self::$instance = new self($config);
            }
            return self::$instance;
        }
        function conexion() {
            $conexion = new ConextionOperation();
            $result = $conexion->seend($this->config);
            return $result;
        }
        public function closePointSaler($offLine, $Cuis, $PuntoVenta){
            $this->cuis=$Cuis;
            $this->puntVenta= $PuntoVenta;
            $classeObject = new ClosePointSaler();
            $classeObject->setOffline($offLine);
            $response = $classeObject->cierrePuntoVenta($this->config, $this->cuis, $this->puntVenta);
            return $response;
        }
        public function queryEvent($offLine, $Cuis, $PuntoVenta=null, $Cufd, $FechaEvento){
            $this->cuis=$Cuis;
            $this->puntVenta=$PuntoVenta;
            $this->cufd=$Cufd;
            $this->fechaEvent=$FechaEvento;
            $classeObject = new QueryEvent();
            $classeObject->setOffline($offLine);
            $response = $classeObject->consultaEventoSignificativo($this->config, $this->cuis, $this->puntVenta,  $this->cufd, $this->fechaEvent);
            return $response;
        }
  
        public function queryPointSaler($offLine, $Cuis){
            $this->cuis=$Cuis;
            $classeObject = new QueryPointSaler();
            $classeObject->setOffline($offLine);
            $response = $classeObject->consultaPuntoVenta($this->config,  $this->cuis);
            return $response;
        }
  
        public function registerEvent($offLine, $Cuis, $PuntoVenta=null, $Cufd, $CufdEvent, $CodMotEvent, $Description, $FechaEventoIni, $FechaEventoFin){
            $this->cuis=$Cuis;
            $this->puntVenta=$PuntoVenta;
            $this->cufd=$Cufd;
            $this->fechaEventoIni=$FechaEventoIni;
            $this->fechaEventoFin=$FechaEventoFin;
            $this->cufdEvent=$CufdEvent;
            $this->description=$Description;
            $this->codEvent=$CodMotEvent;

            $classeObject = new RegisterEvent();
            $classeObject->setOffline($offLine);
            $response = $classeObject->registroEventoSignificativo($this->config, $this->cuis, 
                                                                  $this->puntVenta, $this->codEvent, $this->cufd, 
                                                                  $this->cufdEvent, $this->description, $this->fechaEventoIni, 
                                                                  $this->fechaEventoFin);
            return $response['RespuestaListaEventos'];
        }
        
        public function registerPointSaler($offLine, $Cuis, $CodTipoPuntVenta, $Description, $NombrePuntVenta){
            $this->cuis=$Cuis;
            $this->description=$Description;
            $this->codTipoPuntVenta=$CodTipoPuntVenta;
            $this->nombrePuntVenta=$NombrePuntVenta;

            $classeObject = new RegisterPointSaler();
            $classeObject->setOffline($offLine);
            $response = $classeObject->registroPuntoVenta($this->config, $this->cuis, $this->codTipoPuntVenta, 
                                                          $this->description, $this->nombrePuntVenta);
            // ($config, $cuis, $codTipoPunVenta, $description, $nombreVenta)
            return $response;
        }

        public function registerPointSalerCommissionAgent($offLine){
            $classeObject = new RegisterPointSalerCommissionAgent();
            $classeObject->setOffline($offLine);
            return $classeObject->registerPointSalerCommissionAgent();
        }

        public function VerifyComunication($offLine){
            $classeObject = new VerifyComunication();
            $classeObject->setOffline($offLine);
            return $classeObject->verifyComunication();
        }
    }
?>