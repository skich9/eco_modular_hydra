<?php
class sin_cuis extends CI_Model{

    private $CodPuntoVenta;
    private $Codigo_cuis;
    private $Fecha_vigencia;
    private $Codigo_sucursal;
    private $table = 'sin_cuis';

    function __construct() {
        parent::__construct();
		$this->load->helper('sin_helper');
        $this->load->helper("parametro_economico_helper");
        $this->load->model('administrador/administrador_model');
    }

    function getCuis($puntoVenta){
        $sql = "SELECT codigo_cuis, codigo_sucursal
        FROM sin_cuis
        WHERE  codigo_punto_venta = '$puntoVenta' and fecha_vigencia > now()
        order by fecha_vigencia DESC
        LIMIT 1";
        $query = $this->db->query($sql);
        return $query->result_array();
    }



    // function setCuis($codigoCuis, $fechaVigencia, $codigoSucursal, $codigoPuntoVenta){
    //     $this->CodPuntoVenta = $codigoPuntoVenta;
    //     $this->Codigo_cuis = $codigoCuis;
    //     $this->Fecha_vigencia = $fechaVigencia;
    //     $this->Codigo_sucursal = $codigoSucursal;
    //     try{
    //         $arrData = array('codigo_cuis'=>$this->Codigo_cuis ,
    //                        'fecha_vigencia'=>$this->Fecha_vigencia,
    //                        'codigo_sucursal'=>  $this->Codigo_sucursal,
    //                        'codigo_punto_venta'=>$this->CodPuntoVenta);
    //         // $insert  = $this->db->insert('sin_cuis',$arrData);
    //         $arrData = $this->db->insert('sin_cuis',$arrData);
    //         // return $insert;
    //         return $arrData;
    //     }
    //     catch(Exeption $e){
    //         throw $e;
    //     }
    // }

    // function cuisValido($puntoVenta, $sucursal) {
    //     $this->CodPuntoVenta = $puntoVenta;
    //     $this->Codigo_sucursal = $sucursal;
    //     try {
    //         $sql = "SELECT codigo_cuis, fecha_vigencia, codigo_sucursal, codigo_punto_venta
    //                 from sin_cuis
    //                 where  fecha_vigencia > now() AND codigo_punto_venta='{$this->CodPuntoVenta}'
    //                     AND codigo_sucursal='{$this->Codigo_sucursal}'";
    //         $query = $this->db->query($sql);
    //         $cuis = $query->first_row(); /// objeto codigo_cuis, fecha_vigencia, codigo_sucursal, codigo_punto_venta
    //         if($query->num_rows() == 0){
    //             $cuis = $this->newCuis($this->Codigo_sucursal, $this->CodPuntoVenta);
    //         }
    //     } catch (Exception $th) {
    //         throw $th;
    //     }

    //     return $cuis;
    // }
    function cuisValidoKeyPuntoVenta($puntoVenta, $codSucursal) {
        $this->CodPuntoVenta=$puntoVenta;
        $this->Codigo_sucursal=$codSucursal;
        try {
            $fechaActual = fechaActual();
            $sql = "SELECT codigo_cuis, fecha_vigencia, codigo_sucursal, codigo_punto_venta
                    from sin_cuis
                    where  fecha_vigencia > now() AND codigo_punto_venta='{$this->CodPuntoVenta}' AND codigo_sucursal='{$this->Codigo_sucursal}'
                    order by fecha_vigencia desc ";
            $query = $this->db->query($sql);
            $cuis = $query->result_array();
            // echo print_r($cuis);
            if($query->num_rows()== 0){
                $cuis = $this->newCuis($this->Codigo_sucursal, $this->CodPuntoVenta);
            }
        } catch (Exception $th) {
            throw $th;
        }

        return $cuis;
    }
    // private function newCuis($sucursal, $puntoVenta) {
    //     try{
    //         $parametro_economico = parametro_economico_off_line($this->administrador_model);
    //         $cuis = FactoryServiceCodes::getInstance(getParametrosWS($this))->serviceCuis($parametro_economico, $puntoVenta);

    //         $codeCuis = $cuis['RespuestaCuis']['codigo'];
    //         $fechaVigente=$cuis['RespuestaCuis']['fechaVigencia'];
    //         $saveCuis = $this->setCuis($codeCuis, $fechaVigente, $sucursal, $puntoVenta);
    //     }catch(Exception $e){
    //         throw $e;
    //     }
    //     return $cuis;
    // }
    /// esta es la unica funcion que deberia utiizarse para recuperar el cuis
    
    public function getCuisVigente($offline, $sucursal, $puntoVenta) {
        $sql = "SELECT codigo_cuis, fecha_vigencia, codigo_sucursal, codigo_punto_venta
                from sin_cuis
                where  fecha_vigencia > now() AND codigo_punto_venta='$puntoVenta' AND codigo_sucursal='$sucursal'
                order by fecha_vigencia DESC
                LIMIT 1";
        $query = $this->db->query($sql);
        if($query->num_rows() > 0){
            return $query->result_array()[0];
            // $cuis = $this->newCuis($this->Codigo_sucursal, $this->CodPuntoVenta);
        }
        /// $sucursal no se pasa al factori por que es una constante que se recupera directamente de config_impuestos
        $respuesta_cuis = FactoryServiceCodes::getInstance(getParametrosWS($this))->serviceCuis($offline, $puntoVenta);
        log_message('error','la respuesta de service cuis es:'.print_r($respuesta_cuis,true));
        $codigo_cuis = null;
        $fecha_vigencia = null;
        if(array_key_exists('codigo',$respuesta_cuis["RespuestaCuis"])) {
            $codigo_cuis = $respuesta_cuis["RespuestaCuis"]['codigo'];
        }
        if(array_key_exists('fechaVigencia',$respuesta_cuis["RespuestaCuis"])) {
            $fecha_vigencia = $respuesta_cuis["RespuestaCuis"]['fechaVigencia'];
        }
        if($codigo_cuis == null || $fecha_vigencia == null) {
            throw new Exception("Problemas al recuperar el CUIS, contacte con el administrador");
        }
        $data_cuis = array(
            "codigo_cuis"=>$codigo_cuis,
            "fecha_vigencia"=>$fecha_vigencia,
            "codigo_sucursal"=>$sucursal,
            "codigo_punto_venta"=>$puntoVenta
        );
        if(count($this->cuisExistValid($codigo_cuis)) > 0) {
            // $this->db->replace($this->table, $data_cuis);
            $this->db->set('fecha_vigencia', $fecha_vigencia);
            $this->db->where('codigo_cuis', $codigo_cuis);
            $this->db->update($this->table); 
        }else{
            $this->db->insert($this->table, $data_cuis);
        }
        return $data_cuis;
    }

    private function cuisExistValid($codigo_cuis){
        $sql = "SELECT * from sin_cuis where codigo_cuis='$codigo_cuis'";
        return $this->db->query($sql)->result_array();
    }

    public function sin_cuis_all(){
        $sql = " SELECT codigo_cuis, codigo_sucursal, codigo_punto_venta from sin_cuis";
        return $this->db->query($sql)->result_array();
    }

    // public function get_cuis_punto_venta(){
    //         $sql = "SELECT punto.codigo_punto_venta, punto.nombre, punto.descripcion, punto.ip, punto.crear_cufd, cuis.codigo_cuis, cuis.codigo_sucursal, cuis.fecha_vigencia 
    //         from sin_punto_venta as punto
    //         inner join sin_cuis as cuis on cuis.codigo_punto_venta = punto.codigo_punto_venta
    //         ORDER BY punto.codigo_punto_venta";
    //     return $this->db->query($sql)->result_array();
    // }

    // public function get_cfd_punto_venta(){
    //     $sql = "SELECT spv.codigo_punto_venta, spv.nombre, spv.descripcion, spv.ip, spv.crear_cufd, cufd.codigo_cuis, cufd.codigo_sucursal, cufd.fecha_vigencia
    //     from sin_punto_venta as spv
    //     inner join sin_cufd as cufd on cufd.codigo_punto_venta=spv.codigo_punto_venta
    //     ORDER BY cufd.fecha_vigencia desc limit(select count(*) from sin_punto_venta)";
    //     return $this->db->query($sql)->result_array();
    // }
    public function get_cfd_punto_venta(){
        $sql = "WITH cufd_punto_venta as (
            select max(cufd.fecha_vigencia) as fecha_vigencia, spv.codigo_punto_venta, spv.nombre, spv.descripcion, spv.ip, spv.crear_cufd, cufd.codigo_cuis, cufd.codigo_sucursal
            from sin_punto_venta as spv
            inner join sin_cufd as cufd on cufd.codigo_punto_venta=spv.codigo_punto_venta
            GROUP BY cufd.codigo_punto_venta, spv.codigo_punto_venta, spv.nombre, spv.descripcion, spv.ip, spv.crear_cufd, cufd.codigo_cuis, cufd.codigo_sucursal
            )(
                select * from cufd_punto_venta ORDER BY codigo_punto_venta asc
            )";
        return $this->db->query($sql)->result_array();
    }
}
?>