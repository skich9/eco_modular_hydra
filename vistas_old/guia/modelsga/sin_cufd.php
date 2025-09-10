<?php
require_once(str_replace("\\","/",APPPATH).'core/service_bill/FactoryServiceCodes.php');
class sin_cufd extends CI_Model{

    private $Cufd;
    private $codPuntoVenta;
    private $codSucursal;
    private $codCuis;
    private $table = 'sin_cufd';
    
    function __construct() {
        parent::__construct();
        $this->load->helper('sin_helper');
        $this->load->helper("parametro_economico_helper");
        $this->load->model('administrador/administrador_model');
        $this->load->model('impuestos/sin_cuis');
    }
    public function sin_cufd_vigente($offline, $sucursal, $puntoVenta) {
        $codigo_cuis = $this->codigo_cuis($offline, $sucursal, $puntoVenta);
        //// recuperar mas columnas del cufd
        $sql = "SELECT codigo_cufd, codigo_control, direccion, fecha_vigencia, codigo_cuis, codigo_punto_venta, codigo_sucursal, diferencia_tiempo
                FROM sin_cufd
                WHERE codigo_cuis='{$codigo_cuis}'AND codigo_punto_venta='{$puntoVenta}' AND codigo_sucursal='{$sucursal}' 
                    AND fecha_vigencia > NOW()
                ORDER BY fecha_vigencia DESC
                LIMIT 1";
        log_message('error','la consulta para recuperar el cufd es : '.$sql);
        $query = $this->db->query($sql);
        if($query->num_rows() > 0){
            // $this->update_fecha_cufd( $query->);
            return $query->result_array()[0];
        }
        log_message('error','No recupera de la base de datos deberia recuperar de');
        $data_cufd = $this->data_cufd($offline, $codigo_cuis, $puntoVenta, $sucursal);
        $data_cufd['diferencia_tiempo'] = $this->diferencia_horaria($data_cufd['fecha_vigencia']);
        $this->db->insert($this->table, $data_cufd);
        return $data_cufd;
    }

    public function crear_nuevo_cufd($offline, $sucursal, $puntoVenta) {
        $codigo_cuis = $this->codigo_cuis($offline, $sucursal, $puntoVenta);
        $data_cufd = $this->data_cufd($offline, $codigo_cuis, $puntoVenta, $sucursal);
        $data_cufd['diferencia_tiempo'] = $this->diferencia_horaria($data_cufd['fecha_vigencia']);
        $this->db->insert($this->table, $data_cufd);
        return $data_cufd;
    }

    public function getDataCufd($cufd){
        $sql = "SELECT codigo_cufd, codigo_control, codigo_cuis,direccion, codigo_punto_venta, codigo_sucursal, fecha_vigencia, diferencia_tiempo
        FROM {$this->table} WHERE codigo_cufd = '$cufd'";
        return  $this->db->query($sql)->result_array()[0];
    }

    private function diferencia_horaria($fecha_vigencia_cufd) {
        //$fecha_actual = floatval(fechaActual()); 
        $fecha_cufd = str_replace("-04:00", "", $fecha_vigencia_cufd);
        $datetime_cufd = DateTime::createFromFormat("Y-m-d\TH:i:s.u", $fecha_cufd);
        $cufd_float = floatval($datetime_cufd->format('U.u'));
        log_message("error", "float fecha actual : ". $cufd_float);
        $cufd_float -= 86400;
        $diferencia = round($cufd_float - round(microtime(true),3), 3);
        //$diferencia = round($diferencia, 3);
        // log_message("error", "float fecha actual : ". $fecha_actual);
        log_message("error", "float fecha cufd     ". $cufd_float);
        log_message("error", "Diferencia de tiempo : ". $diferencia);
        return $diferencia;
    }

    private function codigo_cuis($offline, $sucursal, $puntoVenta){
        $mensaje_error = "";
        try {
            $cuis = $this->sin_cuis->getCuisVigente($offline, $sucursal, $puntoVenta);
        } catch (Exception $e) {
            $mensaje_error = $e->getMessage();
        }
        if($mensaje_error!="") {
            throw new Exception("Error al recuperar CUFD VIGENTE. ".$mensaje_error);
        }
        return $cuis["codigo_cuis"];
    }
    
    private function data_cufd($offline, $codigo_cuis, $puntoVenta, $sucursal) {
        $respuesta = FactoryServiceCodes::getInstance(getParametrosWS($this))
                ->serviceCuefd($offline, $codigo_cuis, $puntoVenta);
        log_message('error','NEW CUFD EL QUE SE RECUPERA ES:'.print_r($respuesta, true));
        $codigo_cufd = null;
        $codigo_control = null;
        $direccion = null;
        $fecha_vigencia = null;
        if(array_key_exists('codigo', $respuesta["RespuestaCufd"])) {
            $codigo_cufd = $respuesta["RespuestaCufd"]["codigo"];
        }
        if(array_key_exists('codigoControl', $respuesta["RespuestaCufd"])) {
            $codigo_control = $respuesta["RespuestaCufd"]["codigoControl"];
        }
        if(array_key_exists('direccion', $respuesta["RespuestaCufd"])) {
            $direccion = $respuesta["RespuestaCufd"]["direccion"];
        }
        if(array_key_exists('fechaVigencia',$respuesta["RespuestaCufd"])) {
            $fecha_vigencia = $respuesta["RespuestaCufd"]["fechaVigencia"];
        }
        if($codigo_cufd == null || $codigo_control == null || $direccion == null || $fecha_vigencia == null) {
            throw new Exception('Problemas al recuperara el CUFD, contacte con el administrador');
        }
        $data = array(
            "codigo_cufd"       =>$codigo_cufd,
            "codigo_control"    =>$codigo_control,
            "direccion"         =>$direccion,
            "fecha_vigencia"    =>$fecha_vigencia,
            "codigo_cuis"       =>$codigo_cuis,
            "codigo_punto_venta"=>$puntoVenta,
            "codigo_sucursal"   =>$sucursal
        );
        $fecha_vigencia = str_replace("-04:00", "", $fecha_vigencia);
        $fecha_vigencia = str_replace("T", " ", $fecha_vigencia);
        $fecha_inicio = date('Y-m-d', strtotime($fecha_vigencia."- 1 days"));
        $fecha_inicio_aux = explode(" ", $fecha_vigencia);
        $fecha_inicio = $fecha_inicio." ".$fecha_inicio_aux[1];
        $data['fecha_inicio'] = $fecha_inicio;
        $data['fecha_fin'] = $fecha_vigencia;
        $ultimo_cufd = $this->end_cufd($puntoVenta, $sucursal)[0];
        // log_message("error", "fechas del cufd inicio".print_r($fecha_inicio, true));
        // log_message("error", "fechas del cufd final".print_r($fecha_vigencia, true));
        // log_message("error", "fecha del ultimo cufd".print_r($ultimo_cufd, true));
        if(count($ultimo_cufd) > 0) {
            $this->actualizar_fechas_cufd($fecha_inicio, $ultimo_cufd);
        }
        return $data; 
    }


    public function end_cufd($punto_venta,  $codigo_sucursal){
        $sql = "SELECT codigo_cufd, fecha_vigencia 
                from {$this->table} 
                where   codigo_punto_venta = '$punto_venta' and codigo_sucursal = $codigo_sucursal
                order by fecha_vigencia desc limit 1";
        return $this->db->query($sql)->result_array();
    }

    private function actualizar_fechas_cufd($fecha_inicio, $ultimo_cufd) {
        if($ultimo_cufd['fecha_vigencia'] > $fecha_inicio) {
            $this->db->set('fecha_fin', $fecha_inicio);
            $this->db->where('codigo_cufd', $ultimo_cufd['codigo_cufd']);
            $this->db->update($this->table);
        }
    }

    public function obtener_cufd_rango($fecha, $punto_venta,  $codigo_sucursal) {
        $sql = "SELECT * 
                from sin_cufd 
                where  fecha_inicio <= '$fecha'  and  
                        fecha_fin >'$fecha' and 
                        codigo_punto_venta = '$punto_venta' and 
                        codigo_sucursal = $codigo_sucursal";
        log_message("error", "valor que se recibe del frontend :  ". print_r($fecha, true)."===>la consulta que se ejecuta es:".$sql);
        /// talvez un control para que no se muera o muestre un mensaje
        $query = $this->db->query($sql);
        
        if($query->num_rows() > 0) {
            return $query->result_array()[0];
        }
        return null;
    }
}
?>