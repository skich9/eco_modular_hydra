<?php



class Cobranzas extends CI_Controller
{

	function __construct()
	{
		parent::__construct();
		$this->load->library('Fechas');

	}
	private $tabla = "razon_social";
	public function index()
	{
		if(!$this->session->userdata('cod_est'))
		{
			$this->session->set_userdata('redirect_after_login', current_url());
			redirect(base_url().'index');
		}

		$fechaF = new Fechas();
		$onload='onload="get_gestion();"';
		//$get_datos=$this->buscar_material($this->session->userdata('cod_est'));
		$pensum_estudiante=$this->get_carreras($this->session->userdata('cod_est'));



		// $data= array('fecha'=>$fechaF->FechaFormateada(),'cod_ceta'=> $this->session->userdata('cod_est'),'nombre_est'=> $this->session->userdata('est_namefull'),'datos'=>$get_datos,'onLoad'=>'','comunicados'=>$comunicados);
		$data= array(
			'fecha'=>$fechaF->FechaFormateada(),
			'cod_ceta'=> $this->session->userdata('cod_est'),
			'nombre_est'=> $this->session->userdata('est_namefull'),
			'datos'=>'','onLoad'=>$onload,



		);


		$this->load->view("head");
		$this->load->view("nav", $data);



		$socket_data = $this->consultas->get_socket_config();

		if ($socket_data) {
			$host_con_puerto = $socket_data['valor']; // ej: eea.ceta.edu.bo:49000

			// Detecta si estás en HTTPS real (con proxy o sin)
			$is_https = (
				(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
				(isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
				$_SERVER['SERVER_PORT'] == 443
			);

			if ($is_https) {
				// Si estás en HTTPS, cambia el esquema a WSS y usa solo el host sin puerto
				$parts = explode(':', $host_con_puerto); // separa host y puerto
				$host_solo = $parts[0]; // eea.ceta.edu.bo
				$websocket_url = "wss://$host_solo/ws";
			} else {
				// En HTTP, puedes usar la IP/host con el puerto directamente
				$websocket_url = "ws://$host_con_puerto";
			}
		} else {
			$websocket_url = "";
		}


		$data= array('carreras'=>$pensum_estudiante,
			'email_est'=> $this->session->userdata('email_est'),
			'websocket_url' => $websocket_url, // ✅ Incluir en la vista
		);



		$this->load->view("economico/cobranzas", $data);
		$this->load->view("footer");
	}

	public function get_carreras($cod_ceta)
	{
		$sql="SELECT DISTINCT nombre_carrera, pensum.cod_pensum, carrera.orden as orden_carrera, pensum.orden as orden_pensum
				FROM registro_inscripcion
				INNER JOIN pensum ON registro_inscripcion.cod_pensum =  pensum.cod_pensum
				INNER JOIN carrera ON carrera.cod_carrera = pensum.cod_carrera
				WHERE cod_ceta = $cod_ceta
				ORDER BY carrera.orden ASC, pensum.orden ASC";
		$pensum_estudiante=$this->consultas->consulta_SQL($sql);
		return $pensum_estudiante;
	}

	public function get_ci_rs()
	{
		$cod_est = $_POST['cod_ceta'];
		$ci='';
		$rs='';
		$facturacion_state =$this->consultas->get_facturacion_state();//para ver si seguir cobrando en linea o no
		$estadoo = $facturacion_state['valor'];


		$get_data=$this->consultas->get_ci_rs($cod_est);

		$tiene_pago=$this->consultas->get_transaction_creada($cod_est , date('Y-m-d'));


		if(!is_null($get_data))
		{
			$rs=$get_data->rs;
			$ci=$get_data->numero_doc;
		}
		echo json_encode( array(
				'rs' => $rs,
				'nit' => $ci,
				'facturacion_state' => $estadoo, // Incluimos el estado de facturación en la respuesta
				'tiene_pago'=>$tiene_pago
			));

	}

	public function get_deudas() {
		$cod_ceta = $_POST['cod_ceta'];
		$cod_pensum = $_POST['cod_pensum'];
		$gestion = $_POST['gestion'];

		$facturacion_state =$this->consultas->get_facturacion_state();//para ver si seguir cobrando en linea o no
		$estadoo = $facturacion_state['valor'];
		$facturacion_estado = "$estadoo";

		$deudas = $this->consultas->getDeudasEstudiante($cod_ceta,$gestion,$cod_pensum);
		 log_message('error','las deuda que se recuperan son:'.print_r($deudas,true));
		$deuda_acumulada = 0;
		$nro_cuota_anterior = 0;
		$mes_gestion=['Enero','Febrero','Marzo','Abril', 'Mayo','Junio', 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
		$resultado='';
		$contador=1;
		if (!is_null($deudas)) {
			for($i = 0; $i < count($deudas) ; $i++) {
				$debe = $deudas[$i];
				$dias_multa = 0;
				if($debe->fecha_ini_cobro_multa != null) {
					$fecha_ini_cobro_multa = DateTime::createFromFormat("Y-m-d", $debe->fecha_ini_cobro_multa);
					$fecha_fin_cobro_multa = DateTime::createFromFormat("Y-m-d", $debe->fecha_fin_cobro_multa);
					$diff = $fecha_ini_cobro_multa->diff($fecha_fin_cobro_multa);

					if($diff->format("%R")!="-") {
						$dias_multa = $diff->days + 1;
					}
				}

				$dias_multa = ($dias_multa > 0 ? $dias_multa : 0);		/// los dias de multa no pueden ser negativos
				$deuda = floatval($debe->deuda);

				$multa_x_dia = 0;
				$descuento_multa = 0;
				$multa_cobrar = 0;
				/// cuando el estudiante tiene a la vez 'ARRASTRE' y semestre 'NORMAL' en el segundo que aparece no hay multa ni descuento

				if($debe->desc_multa_completo == 't' || $debe->multa_activa == 'f') {
					$dias_multa = 0;
					log_message('error','Se apllica un descuento de multa completo');
				} else {	/// solo se debe mostrar la multa una vez
					$existe_cuota_posterior = false;
					for($j = $i+1;$j < count($deudas); $j++) {
						$deb_rev = $deudas[$j];
						if($debe->nro_cuota == $deb_rev->nro_cuota) {
							$existe_cuota_posterior = true;
						}
					}
					if(!$existe_cuota_posterior) {
						$multa_x_dia = floatval($debe->monto_multa);
						$descuento_multa = floatval($debe->monto_desc_multa);
						$pago_multa = floatval($debe->pago_multa);

						if($dias_multa * $multa_x_dia - $descuento_multa - $pago_multa > 0 ) {
							$multa_cobrar = ($dias_multa * $multa_x_dia) - $descuento_multa - $pago_multa;
						}
						log_message('error','El valor de pago multa es:'.$multa_cobrar);
					}
				}
				$nro_cuota_anterior = $debe->nro_cuota;
				$kardex_ant = $debe->kardex_economico;
				if($multa_cobrar < 0) {		/// la multa a cobrar nunca puede ser negativo
					$multa_cobrar = 0;
				}
				$deuda_t = $deuda + $multa_cobrar;
				$deuda_acumulada += $deuda + $multa_cobrar;
				$observacion = "";
				if($debe->desc_mens > 0) {
					if($debe->tipo_descuento == 'Descuento por pagos') {
						$observacion .= 'Descuento por cuota: '.$debe->obs_desc_parcial.'. ';
					} else {
						$observacion .= $debe->obs_desc_sem.'. ';
					}
				}


				if($debe->obs_prorroga_multa != null && $debe->obs_prorroga_multa != '') {
					$observacion .= $debe->obs_prorroga_multa.'. ';
				}
				if($debe->obs_desc_multa != null && $debe->obs_desc_multa != '') {
					$observacion .= $debe->obs_desc_multa.'. ';
				}
				$estado='';
				if($contador!=1)
					$estado='disabled';
				$gestion_aux=explode("/", $debe->gestion);
				if($gestion_aux[0]=='1')
					$mes_literal=$mes_gestion[$debe->nro_cuota];
				else
					$mes_literal=$mes_gestion[$debe->nro_cuota+5];

				$resultado.='			<tr class="small" id="'.$contador.'tr">';

				$resultado.='            <td class="text-center ocultable"  data-label="Nº">'.$contador.'</td>';
				$resultado.='            <td class="text-center ocultable"  data-label="Gestión">'.$debe->gestion.'</td>';
				$resultado.='            <td class="text-center ocultable" data-label="Tipo">'.$debe->kardex_economico.'</td>';
				$resultado.='            <td class="text-center ocultable" data-label="Cuota">'.$debe->nro_cuota.'</td>';
				$resultado.='            <td class="text-center labeling" data-label="Mensualidad">'.$mes_literal.'</td>';
				$resultado.='            <td class="text-center labeling"  data-label="Monto Adeudado">'.$deuda_t.'</td>';
				$resultado.='            <td class="text-center ocultable" data-label="Monto Acumulado">'.$deuda_acumulada.'</td>';
				$resultado.='            <td class="text-center ocultable" data-label="Fecha de vencimiento">'.$debe->fecha_ini_cobro_multa.'</td>';
		        $resultado.='            <td class="text-center highlight-checkbox labeling"  data-label="Seleccionar"><input type="checkbox" class="checkboxmes" name="mensualidad" value="'.$deuda_t.'" id="'.$contador.'chk" '.$estado.'  onclick="seleccionar_checkbox('.$contador.');"/></td>';

				$resultado.='            <td  class="ocultableforever" style="display: none;">'.$debe->cod_inscrip.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$multa_cobrar.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$deuda.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->monto_cobro.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->desc_mens.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->turno.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->nro_cuota.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->t_pago_descuento.'</td>';
				$resultado.='            <td class="ocultableforever" style="display: none;">'.$debe->monto_pago.'</td>';
				$resultado.='            <td class="text-center" ><button class="toggle-btn">&#9660;</button></td>';
				$resultado.='        </tr>';
				$contador++;
			}
			$resultado.='			<tr class="table-info">';
			$resultado.='            <th class="text-center" colspan="4">Total a Pagar</th>';
			$resultado.='            <th class="text-center"  id="total">0</th>';

				$resultado .= '<th class="text-center" colspan="2">
								  <button class="btn btn-success" id="btn_validar" type="submit" onclick="validar();" style="display: none;">Pagar por QR</button>
							   </th>';


			$resultado.='            <th class="text-center" colspan="4"></th>';
			$resultado.='        </tr>';
		}
		else
		{
			$resultado='			<tr class="table-info">';
			$resultado.='            <th class="text-center" colspan="11">No existen deudas pencientes</th>';
			$resultado.='        </tr>';
		}
		echo $resultado;
	}
	public function get_qr()
	{
		$cod_est = $_POST['cod_ceta'];
		$gestion = $_POST['gestion'];
		$fecha_actual=date('Y-m-d');

		$codigo=$this->session->userdata('cod_est');
		$sql="	SELECT fecha,monto, fechavencimiento, estado, encode(imagenqr,'base64') as qrimage, id_info, idqr, idtransaccion,procesado,date(fechavencimiento),extract(year from fecha) as anio, factura , alias
				FROM online_transaction_info
				WHERE cod_ceta=$codigo AND gestion ='$gestion' AND estado !='ANULADO'
				ORDER BY id_info
				";
		$get_qr=$this->consultas->consulta_SQL($sql);
		if(!is_null($get_qr))
		{
			$contador=1;
			$resultado='';
			$control=false;
			$estado='';

			foreach ($get_qr -> result() as $fila)
			{ 	$url_factual='http://'.$SERVER['SERVER_NAME'].'/sga/plantilla/plantillas/facturas/'.$fila->anio.''.$fila->factura.'.pdf';
				$btn_download='';
				$alias_codificado ='';
				$facturaBoton='';
				if($this->url_exists($url_factual))
					$btn_download='<a href="'.$url_factual.'" class="btn btn-info btn-xs" role="button" data-toggle="tooltip" data-placement="top" title="Descargar Factura"><span class="fa fa-hand-o-right"></span> Factura</a>';
				$boton_aux='<div><button class="btn btn-warning btn-sm " onclick="detalle('.$fila->id_info.',this)">Detalle</button></div>';
				if($fila->estado=='PAGADO')
				{
					if($fila->procesado=='f')
					{
						$imagen='<h6 class="text-primary">Pendiente de registro en Kardex económico</h6>';
						$boton=$boton_aux;
						$control=true;
					}
					else
					{
						$imagen='<h6 class="text-success">Registrado en Kardex económico</h6>'.$btn_download;
						$boton=$boton_aux;
					}

					$facturaBoton = '<a href="../plantillas/facturas/'.$fila->alias.'.pdf'.'" target="_blank" class="btn btn-primary">Descargar Factura</a>';
					$estado='<span class="btn btn-success">'.$fila->estado.'</span>';


				}
				else
				if($fila->estado=='PENDIENTE' )

				{
					if($fecha_actual!=$fila->fechavencimiento)
					{
						$estado='<span class="btn btn-primary" >'.$fila->estado.'</span><span class="badge badge-danger">EXPIRADO</span>';
						$boton=$boton_aux;
						$imagen='';
					}
					else
					{
						$boton='<div><button class="btn btn-danger btn-sm btn-circle mb-4" onclick="quitar_item('.$fila->id_info.',\''.$fila->idqr.'\')">Anular</button></div><div><a class="btn btn-success btn-sm btn-circle mb-4" download="imagenqr.png" href="data:image/png;base64,'.$fila->qrimage.'">Descargar QR</a></div>'.$boton_aux;
						$imagen='<img class="img-qr-responsive" src="data:image/png;base64,'.$fila->qrimage.'" >';
						$estado='<span class="btn btn-primary">'.$fila->estado.'</span>';
						$control=true;

					}
					$alias_codificado ="". hash('sha256', $fila->alias);
					log_message('error','alias cod es : '.print_r($alias_codificado,true));


				}else if($fila->estado=='PROCESANDO'){
						$boton=$boton_aux;
						$imagen = '<div class="alert alert-info text-center ">
										<strong>Su pago está siendo procesado.</strong><br>
										Este proceso puede tardar hasta <strong>24 horas</strong>.
										Si no recibe su factura en ese tiempo, por favor acérquese a <strong>secretaría</strong> para más información.
									</div>';
						$estado='<span class="btn btn-primary">'.$fila->estado.'</span>';
						$control=true;

					$alias_codificado ="". hash('sha256', $fila->alias);
					log_message('error','alias cod es : '.print_r($alias_codificado,true));



				}
				else
				{
					$boton=$boton_aux;
					$imagen='';
					$estado='<span class="btn btn-danger">'.$fila->estado.'</span>';
				}
				if( $estado != '<span class="btn btn-primary" >PENDIENTE</span><span class="badge badge-danger">EXPIRADO</span>' ){

					$resultado.='			<tr  class="small standby" id="'.$fila->id_info.'tr">';
					$resultado.='            <td  class="text-center"  data-label="Nº">'.$contador.'</td>';
					$resultado.='            <td class="text-center" data-label="Fecha">'.$fila->fecha.'</td>';
					$resultado.='            <td class="text-center" data-label="Monto">'.$fila->monto.'</td>';
					$resultado.='            <td class="text-center" data-label="Vencimiento">'.$fila->fechavencimiento.'</td>';
					$resultado.='            <td class="text-center" data-label="Estado" id="estado">'.$estado.'</td>';
					$resultado.='            <td class="text-center" >'.$imagen.'</td>';
					$resultado .= '    <td class="text-center" id="factura">'.$facturaBoton.'</td>'; // Aquí se muestra el botón de descarga si está PAGADO
					$resultado.='			 <td class="text-center ocultableforever" id="alias" style="display: none;">'.$alias_codificado  . '</td>';
					$resultado.='            <td class="text-center  botones-responsive"  >'.$boton.'</td>';
					$resultado.='        	 </tr>';
					$contador++;
				}

				}

		$data = array(
				'result' => 'COD000',
				'message' => 'Si hay datos',
				'contenido' => $resultado,
				'control' => $control,
			);
		}
		else
		{
			$data = array(
				'result' => 'COD004',
				'message' => 'No hay datos',
			);
		}
		echo json_encode($data);
	}
	public function get_qr_detalle()
	{
		$item_sel = $_POST['item_sel'];
		$codigo=$this->session->userdata('cod_est');


		$sql="	SELECT *
				FROM online_transaction_details
				WHERE id_info=$item_sel
				ORDER BY num_cuota
				";
		$get_qr_det=$this->consultas->consulta_SQL($sql);
		if(!is_null($get_qr_det))
		{
			$resultado='<table width="100%" class=" table-striped table-hover table-bordered table-sm" >
				<thead>
			    <tr class="table-info">
				<th class="text-center" ></th>
				<th class="text-center" >Cuota</th>
		        <th class="text-center" >Mensualidad</th>
		        <th class="text-center" >Monto Adeudado</th>
		    	</tr>
		    	</thead>
				<tbody>	';

			foreach ($get_qr_det -> result() as $fila)
			{
				$monto_calc=$fila->monto+$fila->multa;
				$resultado.='			<tr class="small" >';
				$resultado.='            <td class="text-center">'.$fila->kardex_economico.'</td>';
				$resultado.='            <td class="text-center">'.$fila->num_cuota.'</td>';
				$resultado.='            <td class="text-center">'.$fila->mensualidad.'</td>';
				$resultado.='            <td class="text-center">'.$monto_calc.'</td>';

				$resultado.='        </tr>';
    		}
    		$resultado.='
				</tbody>
			</table>';
		$data = array(
				'result' => 'COD000',
				'message' => 'Si hay datos',
				'contenido' => $resultado,
			);
		}
		else
		{
			$data = array(
				'result' => 'COD004',
				'message' => 'No hay datos',
			);
		}

		echo json_encode($data);
	}
    public function buscar_ci() {
        $S_ci = $this->input->post('S_ci'); // Recibir el NIT desde POST
        // log_message('error','el ci que llega aqui es:'.$S_ci);
		if (empty($S_ci)) {
			echo json_encode(["error" => "El NIT es obligatorio"]);
			return;
		}

		// Construir la URL de la API (misma base URL pero diferente app)
		// Obtener la base URL y escalar un nivel arriba
		$base_url = base_url();
        // log_message('error','la base url que se recupera es:'.$base_url);
		$base_url_padre = dirname(dirname($base_url)); // sube dos niveles en produccion
        // $base_url_padre = dirname($base_url); // sube un nivel en desarrollo
        log_message('error','la base url padre se recupera es:'.$base_url_padre);
		$url_api_impuestos = rtrim($base_url_padre, "/") . "/sga/NitApi/verificar_ci";

		log_message('error','baseurl es xx:'.print_r($url_api_impuestos,true));
		log_message('error','el C.I. que llega como parametro es :'.print_r($S_ci,true));

		// Datos para la API
		$data = json_encode(["ci" => $S_ci]);

		// Consumir la API con cURL
		// Inicializar cURL
		$ch = curl_init($url_api_impuestos);

		// Establecer opciones de cURL
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retornar la respuesta como string
		curl_setopt($ch, CURLOPT_POST, true);           // Método POST
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
	 //	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36"]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// Ejecutar la solicitud cURL
		$response = curl_exec($ch);

		// Verificar si ocurrió un error en cURL
		if (curl_errno($ch)) {
			// Obtener el código de error
			$error_code = curl_errno($ch);
			// Obtener el mensaje de error
			$error_message = curl_error($ch);
			// Registrar el error en la consola
			log_message("error", "cURL Error ({$error_code}): {$error_message}");
			$http_code = "";
			// También puedes usar echo para imprimir en la consola del navegador
			echo "<script>console.error('cURL Error ({$error_code}): {$error_message}');</script>";
		} else {
			// Procesar la respuesta si no hubo errores
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			// Aquí puedes agregar el código para manejar la respuesta
		}

		// Cerrar la sesión cURL
		curl_close($ch);


		// Variable para guardar el estado de validación del NIT en impuestos
		$validado_impuestos = 2; // Default a error (2) si algo falla

		if ($http_code == 200 && $response) {
			$respuesta_api = json_decode($response, true);
			log_message('error','respuesta de nit :'.print_r($respuesta_api,true));

			if (isset($respuesta_api["codigo_excepcion"]) && $respuesta_api["codigo_excepcion"] == 0) {
				// NIT validado con éxito en la API
				$validado_impuestos = 0;
			} elseif (isset($respuesta_api["codigo_excepcion"]) && $respuesta_api["codigo_excepcion"] == 1) {
				// NIT no existe en la API
				$validado_impuestos = 1;
			}
		}else{
			log_message('error','response es 1:'.print_r($response,true));


		}

		// Buscar en la base de datos si la API no responde o responde con error
		$razonSocial = $this->consultas->buscarDocumentoIdentidad($S_ci, null);

		// Preparamos la respuesta final
		$respuesta_final = $razonSocial ? $razonSocial : ["error" => "No se encontró información del CI, debe ingresar la razón social"];

		// Añadimos el campo validado_impuestos a la respuesta final
		$respuesta_final['validado_impuestos'] = $validado_impuestos;

		// Devolver la respuesta
		echo json_encode($respuesta_final);
    }
	public function buscar_nit() {
		$S_nit = $this->input->post('S_nit'); // Recibir el NIT desde POST

		if (empty($S_nit)) {
			echo json_encode(["error" => "El NIT es obligatorio"]);
			return;
		}

		// Construir la URL de la API (misma base URL pero diferente app)
		// Obtener la base URL y escalar un nivel arriba
		$base_url = base_url();
		$base_url_padre =dirname(dirname($base_url)); // sube dos niveles
		$url_api_impuestos = rtrim($base_url_padre, "/") . "/sga/NitApi/verificar_nit";

		log_message('error','baseurl es :'.print_r($url_api_impuestos,true));
		log_message('error','consulta de nit :'.print_r($S_nit,true));

		// Datos para la API
		$data = json_encode(["nit" => $S_nit]);

		// Consumir la API con cURL
		// Inicializar cURL
		$ch = curl_init($url_api_impuestos);

		// Establecer opciones de cURL
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Retornar la respuesta como string
		curl_setopt($ch, CURLOPT_POST, true);           // Método POST
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
	 //	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36"]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		// Ejecutar la solicitud cURL
		$response = curl_exec($ch);

		// Verificar si ocurrió un error en cURL
		if (curl_errno($ch)) {
			// Obtener el código de error
			$error_code = curl_errno($ch);
			// Obtener el mensaje de error
			$error_message = curl_error($ch);
			// Registrar el error en la consola
			log_message("error", "cURL Error ({$error_coede}): {$error_message}");
			$http_code = "";
			// También puedes usar echo para imprimir en la consola del navegador
			echo "<script>console.error('cURL Error ({$error_code}): {$error_message}');</script>";
		} else {
			// Procesar la respuesta si no hubo errores
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			// Aquí puedes agregar el código para manejar la respuesta
		}

		// Cerrar la sesión cURL
		curl_close($ch);


		// Variable para guardar el estado de validación del NIT en impuestos
		$validado_impuestos = 2; // Default a error (2) si algo falla

		if ($http_code == 200 && $response) {
			$respuesta_api = json_decode($response, true);
			log_message('error','respuesta de nit :'.print_r($respuesta_api,true));

			if (isset($respuesta_api["codigo_excepcion"]) && $respuesta_api["codigo_excepcion"] == 0) {
				// NIT validado con éxito en la API
				$validado_impuestos = 0;
			} elseif (isset($respuesta_api["codigo_excepcion"]) && $respuesta_api["codigo_excepcion"] == 1) {
				// NIT no existe en la API
				$validado_impuestos = 1;
			}
		}else{
			log_message('error','response es 3:'.print_r($response,true));


		}

		// Buscar en la base de datos si la API no responde o responde con error
		$razonSocial = $this->consultas->buscarDocumentoIdentidad($S_nit, null);

		// Preparamos la respuesta final
		$respuesta_final = $razonSocial ? $razonSocial : ["error" => "Por favor, ingrese un NIT válido y asegúrese de que esté correctamente escrito."];

		// Añadimos el campo validado_impuestos a la respuesta final
		$respuesta_final['validado_impuestos'] = $validado_impuestos;

		// Devolver la respuesta
		echo json_encode($respuesta_final);
	}


	public function del_qr()
	{
		$cod_ceta = $_POST['cod_ceta'];
		$gestion = $_POST['gestion'];
		$item = $_POST['item'];
		$qrid = $_POST['qrid'];
		$alias='';
		$data_update ='';
		$where_update= '';

		$sql="	SELECT alias
				FROM online_transaction_info
				WHERE cod_ceta=$cod_ceta AND gestion ='$gestion' AND id_info=$item
				";
		$get_alias=$this->consultas->consulta_SQL($sql);

		if(!is_null($get_alias)){
			$alias=$get_alias->row()->alias;
			$where_update = array( 'id_info' => $item );
			$data_update= array( 'estado' => 'ANULADO' , 'estado_factura' => 'ANULADO'  );
		}

		$headers = array(
	        'Method: POST',
	        'Content-Type:application/json',
	        'apikey:'.$this->config->item(API_KEY)
	    );
	    $payload = array(
	        'password' => $this->config->item(PASSWORD),
	        'username' => $this->config->item(USER_NAME)
	    );
	    $url=$this->config->item(URL_AUTENTICATION);
	    $ch = curl_init();
		if(!$ch){
			die("Couldn't initialize a cURL handle");
		}
		log_message('error','la url que se utiliza es:'.$url);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 100);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$result = curl_exec($ch); // execute
		log_message('error','respuesta generar token curl es:'.print_r($result,true));
	    //echo $result;
	    $resultado=json_decode($result,true);
	    if($resultado['codigo']=='OK')
	    {
	    	$headers = array(
		        'Method: POST',
		        'Content-Type:application/json',
		        'apikeyServicio:'.$this->config->item(API_KEY_SERVICIO),
	 			'Authorization: Bearer '. $resultado['objeto']['token']
		    );
		    $payload = array(
		        'alias' =>$alias,
		    );
		    $url=$this->config->item(URL_TRANSFER).'inhabilitarPago';
		    $ch = curl_init();
			if(!$ch){
				die("Couldn't initialize a cURL handle");
			}
			log_message('error','la url para generarQR es:'.$url);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 100);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			$result = curl_exec($ch); // execute
			log_message('error','respuesta generarQR curl es:'.print_r($result,true));
			$resultado=json_decode($result,true);
			if($resultado['codigo']=='0000')
	    	{
				// Actualizar los datos
				$this->db->where($where_update);
				if($this->db->update('online_transaction_info',$data_update))
					$mensaje = array(
				        'codigo' => 'EXITO',
				        'mensaje'=>'La transacción fué anulada con éxito',
						'alias' => $alias,
			    	);
				else
					$mensaje = array(
				        'codigo' => 'ERROR',
				        'mensaje'=>'No se pudo actualizar la base de datos',
						'alias' => $alias,
			    	);

			    echo json_encode($mensaje);
	    	}
	    	else
		    {
				log_message('error','1 respuesta curl es:'.$resultado['codigo']);
		    	$mensaje = array(
			        'codigo' => 'ERROR',
			        'mensaje'=>'Error al anular la transacción. Intentelo más tarde o comuníquese con el Departamento de Sistemas.',
			        'mensaje_api' => $resultado,
			    );
			    echo json_encode($mensaje);
		    }
	    }
	    else
	    {
            log_message('error','lugar 1 xxxxxx');
			log_message('error','2 respuesta curl es:'.$resultado['codigo']);
	    	$mensaje = array(
		        'codigo' => 'ERROR',
		        'mensaje'=>'Error al obtener la autentificación. Intentelo más tarde o comuníquese con el Departamento de Sistemas.',
		        'mensaje_api' => $resultado['objeto'],
		    );
		    echo json_encode($mensaje);
	    }

	}

    // Modificar la consulta del estado del estudiante
	public function registrar_pago()
	{
		$cod_ceta = $_POST['cod_ceta'];
		$gestion = $_POST['gestion'];
		$cod_pensum = $_POST['cod_pensum'];
		$nit = $_POST['nit'];
		$rrss = $_POST['rrss'];
        $complemento = $_POST['complemento'];
		$tipo = $_POST['tipo'];


		$tipos_doc = [
			1 => 'Ci:',
			2 => 'Cex:',
			// 3 => 'PAS:',
			// 4 => 'OD:',
			5 => 'Nit:',
		];

        $amount = $_POST['amount'];
		$detalle = json_decode($_POST['detalle']);

		$tiene_pago=$this->consultas->get_transaction_creada($cod_ceta , date('Y-m-d'));

		log_message('error','tiene pago es :'.$tiene_pago);
		if ($tiene_pago) {
			// Si ya tiene una transacción pendiente, devolvemos error
			$error= array(
				'codigo' => 'ERROR',
				'mensaje' => 'Ya tienes una transacción pendiente para hoy',
				'mensaje_api' => '' );

			echo json_encode($error);
			return;
		}





		$headers = array(
	        'Method: POST',
	        'Content-Type:application/json',
	        'ApiKey:'.$this->config->item(API_KEY)
	    );
	    $payload = array(
	        'password' => $this->config->item(PASSWORD),
	        'username' => $this->config->item(USER_NAME)
	    );
	    $url=$this->config->item(URL_AUTENTICATION);
	    $ch = curl_init();
		if(!$ch){
			die("Couldn't initialize a cURL handle");
		}
		log_message('error','la url que se utiliza es:'.$url);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 100);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$result = curl_exec($ch); // execute
		//log_message('error','3 respuesta curl es:'.print_r($result,true));
	    //echo $result;
	    $resultado=json_decode($result,true);
	    if($resultado['codigo']=='OK')
	    {
            $carrera = $this->consultas->get_carrera_callback($cod_pensum);

            if($carrera == null) {
                throw new Exception('No se puede recuperar el código de Carrera por favor contacte con el administrador');
            }
            /// verificar si la fecha de vencimiento es hasta las 23:59:59  ====>>> SI NO CUMPLE HAY QUE CORREGIR
	    	$fechavencimiento = date("d/m/Y");
	    	$alias=$cod_ceta.$carrera->cod_carrera.date("HisdmY");

            log_message('error','API key servicios es:'.$this->config->item(API_KEY_SERVICIO));
            log_message('error','autorization:'. $resultado['objeto']['token']);

	    	$headers = array(
		        'Method: POST',
		        'Content-Type:application/json',
		        'apikeyServicio:'.$this->config->item(API_KEY_SERVICIO),
	 			'Authorization: Bearer '. $resultado['objeto']['token']
		    );
            log_message('error','la configuracion de la cabecera es:'.print_r($headers,true));
            $environment = $this->config->item(ENVIRONMENTQR);
            log_message('error','El environment que se recupera es:'.$environment);

            $url_callback = "";
            if($environment == 'production') {
                $url_callback = $carrera->callback . "/confirmarPago";
            } else {
                $url_callback = $carrera->callback . "/confirmarPagoDesarrollo";
            }
            /// ANALIZAR LA POSIBILIDAD DE MODIFICAR EL DETALLE DE LA GLOSA  ====>>> TOMAR EN CUENTA OSMAR
		    $payload = array(
		        'alias' =>$alias,
			    'callback' => $url_callback,
			    'detalleGlosa' =>'Mensualidades pendientes',
			    'monto' =>$amount,
			    'moneda' =>'BOB',
			    'fechaVencimiento' =>$fechavencimiento,
			    'tipoSolicitud' =>'API',
			    'unicoUso' =>'true',
		    );
            log_message('error','el payload que se utiliza es:'.print_r($payload,true));
		    $url=$this->config->item(URL_TRANSFER).'generaQr';
            log_message('error','la URL :'.$url);
		    $ch = curl_init();
			if(!$ch){
				die("Couldn't initialize a cURL handle");
			}
			// log_message('error','la url que se utiliza es:'.$url);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 100);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			$result = curl_exec($ch); // execute

			$resultado=json_decode($result,true);
			if($resultado['codigo']=='0000')
	    	{
				$imagen_base64 = $resultado['objeto']['imagenQr'];
				$monto = ' BOB ' . number_format($amount, 2, '.', ',');
				$fecha = date('d/m/Y'); // o puedes usar una fecha fija si prefieres
				$esUnico = 'Si';
				$rrss = mb_convert_encoding($rrss, 'UTF-8', 'auto');
				$base_path =  dirname(dirname(dirname(_DIR_)));
				$fuente = $base_path  . '/plantillas/fonts/DejaVuSans.ttf';
				$fuente_negrilla = $base_path . '/plantillas/fonts/DejaVuSans-Bold.ttf';
				$nit = utf8_encode($nit);
				//log_message('error', 'Inicio del proceso para generar imagen con texto ' .  $tipo);

				// Decodificar la imagen base64
				// Decodificar la imagen base64
				$imagen_binaria = base64_decode($imagen_base64);
				$image_qr = imagecreatefromstring($imagen_binaria);

				// Dimensiones del QR
				// Reducir QR un poco
				$qr_width = imagesx($image_qr);
				$qr_height = imagesy($image_qr);
				$nuevo_qr_width = $qr_width * 0.8;
				$nuevo_qr_height = $qr_height * 0.8;

				$qr_escalado = imagecreatetruecolor($nuevo_qr_width, $nuevo_qr_height);
				imagecopyresampled($qr_escalado, $image_qr, 0, 0, 0, 0, $nuevo_qr_width, $nuevo_qr_height, $qr_width, $qr_height);

				// Nueva imagen más alta y ancha
				$nueva_ancho = $nuevo_qr_width + 40;
				$nueva_altura = $nuevo_qr_height + 85;
				$nueva_imagen = imagecreatetruecolor($nueva_ancho, $nueva_altura);

				// Colores
				$blanco = imagecolorallocate($nueva_imagen, 255, 255, 255);
				$negro = imagecolorallocate($nueva_imagen, 0, 0, 0);

				// Fondo blanco
				imagefilledrectangle($nueva_imagen, 0, 0, $nueva_ancho, $nueva_altura, $blanco);

				// Copiar QR centrado horizontalmente
				$x_qr = ($nueva_ancho - $nuevo_qr_width) / 2;
				imagecopy($nueva_imagen, $qr_escalado, $x_qr, 10, 0, 0, $nuevo_qr_width, $nuevo_qr_height);

				// Escribir textos con la fuente TTF
				$tamano_fuente = 9; // Puedes ajustar el tamaño de la fuente según lo necesites
				$y_texto = $nuevo_qr_height + 20;


				// Texto centrado debajo del QR
				$texto_inicial = 'La factura será emitida con los siguientes datos';

				// Calcular el ancho del texto y centrarlo
				$bbox = imagettfbbox($tamano_fuente-1, 0, $fuente_negrilla, $texto_inicial);
				$ancho_texto = $bbox[2] - $bbox[0];
				$x_texto = ($nueva_ancho - $ancho_texto) / 2; // Centrar el texto

				// Escribir el texto centrado debajo del QR
				$y_texto = $nuevo_qr_height + 20; // Espacio debajo del QR
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, $x_texto, $y_texto, $negro, $fuente_negrilla, $texto_inicial);

				$y_texto += 15;
				$etiqueta_doc = isset($tipos_doc[$tipo]) ? $tipos_doc[$tipo] : 'Doc:'; // Fallback en caso de valor inválido
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 10, $y_texto, $negro, $fuente_negrilla, $etiqueta_doc);
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 42, $y_texto, $negro, $fuente, $nit);

				$y_texto += 15;
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 10, $y_texto, $negro, $fuente_negrilla, 'R. Social:');
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 72, $y_texto, $negro, $fuente, $rrss);

				$y_texto += 15;
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 10, $y_texto, $negro, $fuente_negrilla, 'Monto:');
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, 52, $y_texto, $negro, $fuente, $monto);
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, $nueva_ancho - 120, $y_texto, $negro, $fuente_negrilla, 'Pago Unico:');
				imagettftext($nueva_imagen, $tamano_fuente-1, 0, $nueva_ancho - 45, $y_texto, $negro, $fuente, $esUnico);

				// Capturar salida
				ob_start();
				imagepng($nueva_imagen);
				$imagen_con_texto = ob_get_clean();
				$imagen_con_texto_base64 = base64_encode($imagen_con_texto);

				// Limpiar
				imagedestroy($image_qr);
				imagedestroy($qr_escalado);
				imagedestroy($nueva_imagen);
				//log_message('error', 'el base64 de la imagen es ' . $imagen_con_texto_base64);

				$resultado['objeto']['imagenQr'] = $imagen_con_texto_base64 ;


				$sql="INSERT into online_transaction_info (cod_ceta,fecha,monto,idqr,imagenqr,fechavencimiento, estado, idtransaccion, alias, nit, rs, gestion, tipo_doc, complemento,cod_pensum) VALUES(".$cod_ceta.",'".date('c')."',".$amount.",'".$resultado['objeto']['idQr']."',decode('".$resultado['objeto']['imagenQr']."','base64') ,'".date('Y-m-d')."', 'PENDIENTE',".$resultado['objeto']['idTransaccion'].",'".$alias."','".$nit."','".$rrss."','".$gestion."',".$tipo.",'".$complemento."','".$cod_pensum."')";

				//log_message('error','consulta generada es-->:'.print_r($sql,true));

				$this->db->query($sql);

				$id_last=$this->db->insert_id();
				for ($i = 0; $i < sizeof($detalle); $i++) {
					// Inicializar la variable mensualidad
					$mensualidad = $detalle[$i]->mensualidad;

					// Verificar si deuda es menor que monto_cobro
					if ($detalle[$i]->deuda + $detalle[$i]->desc_mens < $detalle[$i]->monto_cobro) {
						// Si tipo_kardex es "NORMAL", agrega "Mens. Parcial"
						if ($detalle[$i]->tipo_kardex == 'NORMAL') {
							$mensualidad = "Mens. Parcial " . $detalle[$i]->mensualidad;
						}
						// Si tipo_kardex es "ARRASTRE", agrega "Nivelacion. Parcial"
						elseif ($detalle[$i]->tipo_kardex == 'ARRASTRE') {
							$mensualidad = "Nivelacion. Parcial " . $detalle[$i]->mensualidad;
						}
					}else{
						// Si tipo_kardex es "NORMAL", agrega "Mens. Parcial"
						if ($detalle[$i]->tipo_kardex == 'NORMAL') {
							$mensualidad = "Mens. " . $detalle[$i]->mensualidad;
						}
						// Si tipo_kardex es "ARRASTRE", agrega "Nivelacion. Parcial"
						elseif ($detalle[$i]->tipo_kardex == 'ARRASTRE') {
							$mensualidad = "Nivelacion. " . $detalle[$i]->mensualidad;
						}
					}

					// Construir el array con los datos a insertar
					$data = array(
						'id_info'          => $id_last,
						'cod_ceta'        => $this->session->userdata('cod_est'),
						'gestion'         => $detalle[$i]->gestion,
						'cod_inscrip'     => $detalle[$i]->cod_inscrip,
						'kardex_economico'=> $detalle[$i]->tipo_kardex,
						'num_cuota'       => $detalle[$i]->cuota,
						'mensualidad'     => $mensualidad, // Usamos el vaflor actualizado
						'monto'           => $detalle[$i]->deuda,
						'multa'           => $detalle[$i]->multa_cobrar,
						'precio_unitario' => $detalle[$i]->monto_cobro,
						'descuento'       => $detalle[$i]->desc_mens,
						'turno'           => $detalle[$i]->turno,
						'nro_cuota'       => $detalle[$i]->nro_cuota,
						't_pago_descuento'=> $detalle[$i]->t_pago_descuento,
						'monto_pago'=> $detalle[$i]->monto_pago,
					);

					// Insertar en la tabla
					$this->consultas->insert_table('online_transaction_details', $data);
				}
				$mensaje = array(
			        'codigo' => 'EXITO',
			        'idQr' => $resultado['objeto']['idQr'],
					'imagenQr' => $resultado['objeto']['imagenQr'],
					'idTransaccion' => $resultado['objeto']['idTransaccion'],
					'fechavencimiento' => date('Y-m-d'),
					'alias' => $alias
			    );
			    echo json_encode($mensaje);
	    	}
	    	else
		    {
                log_message('error','lugar 2 xxxxxx');
				log_message('error','5 respuesta curl es:'.$resultado['codigo']);
		    	$error = array(
			        'codigo' => 'ERROR',
			        'mensaje'=>'Error al obtener la autentificación. Intentelo más tarde o comuníquese con el Departamento de Sistemas.',
			        'mensaje_api' => $resultado['objeto']
			    );
			    echo json_encode($error);
		    }
	    }
	    else
	    {
            log_message('error','lugar 2 xxxxxx');
			log_message('error','6 respuesta curl es:'.$resultado['codigo']);
	    	$error = array(
		        'codigo' => 'ERROR',
		        'mensaje'=>'Error al obtener la autentificación. Intentelo más tarde o comuníquese con el Departamento de Sistemas.',
		        'mensaje_api' => $resultado['objeto']
		    );
		    echo json_encode($error);
	    }

	}
    function ajustarTexto($texto, $anchoMaximo, $fuente, $tamanoFuente) {
        $lineas = [];
        $palabras = explode(' ', $texto);
        $lineaActual = '';
        foreach ($palabras as $palabra) {
            $lineaPrueba = $lineaActual . ' ' . $palabra;
            $bbox = imagettfbbox($tamanoFuente, 0, $fuente, $lineaPrueba);
            $anchoLinea = $bbox[2] - $bbox[0];
            if ($anchoLinea <= $anchoMaximo) {
                $lineaActual = $lineaPrueba;
            } else {
                $lineas[] = trim($lineaActual);
                $lineaActual = $palabra;
            }
        }
        if (!empty($lineaActual)) {
            $lineas[] = trim($lineaActual);
        }
        return $lineas;
    }
	function url_exists2( $url = NULL ) {

	    if( empty( $url ) ){
	        return false;
	    }

	    $options['http'] = array(
	        'method' => "HEAD",
	        'ignore_errors' => 1,
	        'max_redirects' => 0
	    );
	    $body = @file_get_contents( $url, NULL, stream_context_create( $options ) );
		log_message('error','entro a verificar en stream_context_create:'.print_r($body,true));

	    // Ver http://php.net/manual/es/reserved.variables.httpresponseheader.php
	    if( isset( $http_response_header ) ) {
		log_message('error','entro a verificar en TRUE ISSET:');

	        sscanf( $http_response_header[0], 'HTTP/%*d.%*d %d', $httpcode );

	        // Aceptar solo respuesta 200 (Ok), 301 (redirección permanente) o 302 (redirección temporal)
	        $accepted_response = array( 200, 301, 302 );
	        if( in_array( $httpcode, $accepted_response ) ) {
	            return true;
	        } else {
	            return false;
	        }
	     } else {

		log_message('error','NO PUDO INGRESAR a verificar en TRUE ISSET:');
	         return false;
	     }
	}
	function url_exists3($url) {
	    $h = get_headers($url);
		log_message('error','entro a verificar en get_headers:'.print_r($h,true));

	    $status = array();
	    preg_match('/HTTP\/.* ([0-9]+) .*/', $h[0] , $status);
	    return ($status[1] == 200);
	}
	function url_exists($url)
	{
	   $handle = @fopen($url, "r");
	   if ($handle == false)
	          return false;
	   fclose($handle);
	      return true;
	}
}
?>