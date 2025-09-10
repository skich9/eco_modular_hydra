<?php 
class Comparar {
    private $array_db;
    private $array_sin;
    private $array_diff_sync;

    // private $criterio;

    function __construct($array_db, $array_sin){
        $this->array_db = $array_db;
        $this->array_sin = $array_sin;
        $this->array_diff_sync = [];
    }
    //Ordenamiento de menor a mayor para ser utilizado
    private function build_sorter($clave) {
        return function ($a, $b) use ($clave) {
            return strnatcmp($a[$clave], $b[$clave]);
        };
    }
    function ordenar_db($criterio) {
        usort($this->array_db, $this->build_sorter($criterio));
        return $this->array_db;
    }
    function ordenar_sin($criterio) {
        usort($this->array_sin, $this->build_sorter($criterio));
        return $this->array_sin;
    }
  
    function array_diff_db_sin($keys_db, $keys_sin,  $parametro=0, $leyenda=false) {
        $this->array_db['keys'] = $keys_db;
        $this->array_sin['keys'] = $keys_sin;
        if(count($this->array_db) == count($this->array_sin)){
            $array_small = $this->array_sin;    
            $array_long = $this->array_db;
        }
        else{
            $array_small = (count($this->array_db) < count($this->array_sin))? $this->array_db: $this->array_sin; 
            $array_long = (count($this->array_db) > count($this->array_sin))? $this->array_db: $this->array_sin;
        }        
        $resultado =  $this->buscar_elemento($array_long, $array_long['keys'], $array_small, $array_small['keys'],  $parametro);
        $this->array_diff_sync  = $resultado;
        return $resultado;
    }

    private function buscar_elemento(&$array_long, $key_long, &$array_small, $key_small,  $parametro=0) {
        unset($array_long['keys']);
        unset($array_small["keys"]);
        $resultado = [];
        foreach($array_small as $buscar) {
            array_push($resultado, $this->buscar_parecido($buscar, $key_small, $array_long, $key_long,  $parametro));
        }
        foreach($array_long as $dato) {
            array_push($resultado, [$dato, $this->llaves_default($key_small)]);
        }
        return $resultado;
    }

    private function llaves_default($key_long){
        $result=[];
        foreach($key_long as $keys) {
            $result[$keys]="";
        }
        return $result;
    }
    
    private function buscar_parecido($buscar, $key_small, &$array_long, $key_long, $parametro=0) {
        $resultado = [];
        for ($i = 0; $i < count($array_long); $i++) {
            if($array_long[$i][$key_long[$parametro]] == $buscar[$key_small[$parametro]]){
                $aux = [$array_long[$i], $buscar];
                $resultado = $aux;
                unset($array_long[$i]);
                $array_long = array_values($array_long);
                break;
            }
            else{
                $resultado = [$this->llaves_default($key_long), $buscar];
            }
        }
        return $resultado;
    }

}
?>