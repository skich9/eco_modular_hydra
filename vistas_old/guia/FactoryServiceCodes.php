<?php
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/CheckNight.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/Cuefd.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/CuefdMasivo.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/Cuis.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/CuisMasivo.php');
require_once(str_replace("\\","/",APPPATH).'core/service_bill/service_obtainingCodes/ConextionCode.php');
 class FactoryServiceCodes{
    private $config = [];
    private static $instance;
    private function __construct($config) {
        $this->config = $config;
    }
    public static function getInstance($config) {
        if (!self::$instance instanceof self) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    function conexion() {
        $conexion = new ConextionCode();
        // $conexion->setOffline($offLine);
        $result = $conexion->seend($this->config);							
        return $result;
    }
    // //metodos de servicios de obtencio de codigos
    public function nitCheck($offLine, $cuis,$nitParaVerificacion){
        $activitys = new CheckNight();
        $activitys->setOffline($offLine);
        $result = $activitys->verificarNit($this->config, $cuis, $nitParaVerificacion);
        return  $result;
    }
    public function serviceCuefd($offLine, $cuis, $puntoVenta=null){
        $activitys = new Cuefd();
        $activitys->setOffline($offLine);
        $result = $activitys->obtenerCufd($this->config, $cuis, $puntoVenta);
        return  $result;
    }
    public function serviceCuefdMasivo($offLine, $cuis,$puntoVenta=null){        
        $activitys = new CuefdMasivo();
        $activitys->setOffline($offLine);
        $result = $activitys->cufdMasivo($this->config,$cuis,$puntoVenta);
        return  $result;
    }
    public function serviceCuis($offLine, $puntoVenta=null){        
        $activitys = new Cuis();
        $activitys->setOffline($offLine);
        $result = $activitys->obtenercuis($this->config, $puntoVenta);
        return  $result;
    }
    public function serviceCuisMasivo($offLine, $puntoVenta=null){        
        $activitys = new CuisMasivo();
        $activitys->setOffline($offLine);
        $result = $activitys->objcuisMasivo($this->config, $puntoVenta);
        return  $result;
    }
}
?>