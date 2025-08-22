CREATE TABLE `cache` (
  `key` varchar(255) PRIMARY KEY NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int NOT NULL
);

CREATE TABLE `cache_locks` (
  `key` varchar(255) PRIMARY KEY NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL
);

CREATE TABLE `compromisos` (
  `id_compromisos` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `cod_ceta` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_compromiso` date NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `estado` varchar(255) NOT NULL,
  `descripcion` text,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `costo_detalle` (
  `cod_costo_detalle` bigint NOT NULL AUTO_INCREMENT,
  `costo_id` bigint NOT NULL,
  `id_descuentoDetalle` int DEFAULT null,
  `usuario` varchar(255) NOT NULL,
  `semestre` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `cuota` int DEFAULT null,
  `tipo_inscripcion` varchar(20) DEFAULT null,
  `turno` varchar(20) DEFAULT null,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`cod_costo_detalle`, `costo_id`)
);

CREATE TABLE `costos` (
  `cod_costo` bigint NOT NULL AUTO_INCREMENT,
  `cod_pensum` varchar(50) NOT NULL,
  `id_descuento` int DEFAULT null,
  `nombre` text NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `gestion` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`cod_costo`, `cod_pensum`)
);

CREATE TABLE `gestion` (
  `gestion` varchar(30) PRIMARY KEY NOT NULL,
  `fecha_ini` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `orden` int NOT NULL,
  `fecha_graduacion` date DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `cuentas_bancarias` (
  `id_cuentas_bancarias` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `banco` varchar(50) NOT NULL,
  `numero_cuenta` varchar(100) NOT NULL,
  `tipo_cuenta` varchar(100) NOT NULL,
  `titular` varchar(100) NOT NULL,
  `habilitado_QR` bool DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `cuotas` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text,
  `monto` decimal(10,2) NOT NULL,
  `fecha_vencimiento` date DEFAULT null,
  `estado` varchar(255) DEFAULT null,
  `tipo` varchar(255) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `descuento_detalle` (
  `id_descuento_detalle` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `id_descuento` bigint NOT NULL,
  `id_usuario` bigint NOT NULL,
  `id_inscripcion` bigint DEFAULT null,
  `id_cuota` bigint DEFAULT null,
  `cod_Archivo` text,
  `fecha_registro` timestamp NOT NULL,
  `fecha_solicitud` date NOT NULL,
  `observaciones` text,
  `tipo_inscripcion` text,
  `turno` text,
  `semestre` text,
  `meses_descuento` text,
  `estado` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `descuentos` (
  `cod_ceta` bigint NOT NULL,
  `cod_pensum` text NOT NULL,
  `cod_inscrip` bigint NOT NULL,
  `id_usuario` bigint NOT NULL,
  `id_descuentos` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `observaciones` text,
  `porcentaje` decimal(5,2) NOT NULL,
  `tipo` varchar(255) DEFAULT null,
  `estado` varchar(255) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `egresos` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_egreso` date NOT NULL,
  `tipo` varchar(255) NOT NULL,
  `descripcion` text,
  `num_factura` varchar(255) DEFAULT null,
  `num_comprobante` varchar(255) DEFAULT null,
  `fecha_factura` date DEFAULT null,
  `fecha_recibo` date DEFAULT null,
  `autorizacion` varchar(255) DEFAULT null,
  `estado` varchar(255) DEFAULT null,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `estudiantes` (
  `cod_ceta` bigint PRIMARY KEY NOT NULL,
  `ci` varchar(50) NOT NULL,
  `nombres` varchar(70) NOT NULL,
  `ap_paterno` varchar(70) NOT NULL,
  `ap_materno` varchar(70) NOT NULL,
  `email` varchar(100) DEFAULT null,
  `cod_pensum` varchar(50) NOT NULL,
  `estado` varchar(50) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `failed_jobs` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT (CURRENT_TIMESTAMP)
);

CREATE TABLE `formas_cobro` (
  `id_forma_cobro` varchar(255) PRIMARY KEY NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text,
  `estado` varchar(255) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `recibo` (
  `nro_recibo` int NOT NULL AUTO_INCREMENT,
  `anio` int NOT NULL,
  `id_usuario` varchar(200) NOT NULL,
  `descuento_adicional` float(8) DEFAULT null,
  `id_forma_cobro` int NOT NULL,
  `complemento` varchar(255) DEFAULT null,
  `cod_tipo_doc_identidad` int NOT NULL,
  `monto_gift_card` decimal(10,2) DEFAULT null,
  `num_gift_card` varchar(50) DEFAULT null,
  `tipo_emision` int DEFAULT null,
  `codigo_excepcion` int DEFAULT null,
  `codigo_doc_sector` int DEFAULT null,
  `tiene_reposicion` bool DEFAULT null,
  `periodo_facturado` varchar(50) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`nro_recibo`, `anio`)
);

CREATE TABLE `nota_respaldo` (
  `anio` int NOT NULL,
  `correlativo` int NOT NULL,
  `id_usuario` varchar(100),
  `fecha_nota` timestamp,
  `cod_ceta` int,
  `monto` decimal(10,2),
  `nro_recibo` int,
  `nro_factura` int,
  `observacion` text,
  `banco_deposito` varchar(50),
  `nro_transaccion` bigint,
  `fecha_transaccion` date,
  `banco_origen` varchar(200),
  `nro_tarjeta` varchar(200),
  `anulado` bool,
  `tipo_nota` varchar(10),
  PRIMARY KEY (`anio`, `correlativo`)
);

CREATE TABLE `factura` (
  `nro_factura` int NOT NULL AUTO_INCREMENT,
  `anio` int NOT NULL,
  `tipo_factura` text NOT NULL,
  `id_parametros_factura` tinyint NOT NULL,
  `cuf` varchar(75) NOT NULL,
  `cufd` varchar(100) NOT NULL,
  `codigo_punto_venta` tinyint,
  `codigo_sucursal` int NOT NULL,
  `fecha_emision` timestamp(6),
  `nombre_razonSocial_beneficiario` text,
  `codigo_Tipo_Documento` int,
  `numeroDocumento` varchar(50),
  `complemento` varchar(10),
  `codigoCliente` varchar(50),
  `nombreEstudiante` text,
  `perido_facturado` text,
  `codigoMetodoCobro` int,
  `numeroTarjeta` varchar(255),
  `codigoMoneda` int,
  `tipoCambio` int,
  `montoTotalMoneda` decimal(10,2),
  `montoGiftCard` decimal(10,2),
  `descuentoAdicional` decimal(10,2),
  `codigoExcepcion` int,
  `cafc` varchar(50),
  `leyenda` text,
  `id_usuario` bigint,
  `codigoDocumentoSector` int,
  `estado` varchar(255) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`nro_factura`, `anio`, `tipo_factura`)
);

CREATE TABLE `historial_observaciones` (
  `id_observacion` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `cod_ceta` bigint NOT NULL,
  `gestion` varchar(255) NOT NULL,
  `cod_pensum` varchar(255) NOT NULL,
  `cod_inscrip` varchar(255) NOT NULL,
  `tipo_inscripcion` varchar(255) NOT NULL,
  `tipo_observacion` varchar(255) NOT NULL,
  `observacion` text NOT NULL,
  `fecha_observacion` date NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `otros_ingresos` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `cod_ceta` varchar(255) DEFAULT null,
  `monto` decimal(10,2) NOT NULL,
  `fecha_ingreso` date NOT NULL,
  `tipo` varchar(255) NOT NULL,
  `descripcion` text,
  `num_factura` varchar(255) DEFAULT null,
  `num_comprobante` varchar(255) DEFAULT null,
  `fecha_factura` date DEFAULT null,
  `fecha_recibo` date DEFAULT null,
  `estado` varchar(255) DEFAULT null,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `inscripciones` (
  `cod_inscrip` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `id_usuario` int NOT NULL,
  `cod_ceta` bigint NOT NULL,
  `cod_pensum` varchar(50) NOT NULL,
  `nro_materia` tinyint NOT NULL,
  `nro_materia_aprob` int NOT NULL,
  `gestion` varchar(20) NOT NULL,
  `tipo_estudiante` varchar(20) DEFAULT null,
  `fecha_inscripcion` date DEFAULT null,
  `tipo_inscripcion` varchar(30) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `job_batches` (
  `id` varchar(255) PRIMARY KEY NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT null,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT null
);

CREATE TABLE `jobs` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint NOT NULL,
  `reserved_at` int DEFAULT null,
  `available_at` int NOT NULL,
  `created_at` int NOT NULL
);

CREATE TABLE `logs` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `accion` varchar(255) NOT NULL,
  `tabla` varchar(255) NOT NULL,
  `registro_id` bigint NOT NULL,
  `descripcion` text,
  `fecha` datetime NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `matriculas` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `cod_inscrip` bigint NOT NULL,
  `cod_ceta` varchar(255) NOT NULL,
  `cod_pensum` varchar(255) NOT NULL,
  `gestion` varchar(255) NOT NULL,
  `kardex_economico` varchar(255) NOT NULL,
  `num_pago_matri` int NOT NULL,
  `costo` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) NOT NULL,
  `matriculatotal` decimal(10,2) NOT NULL,
  `pago_completo` tinyint(1) NOT NULL,
  `num_factura` varchar(255) DEFAULT null,
  `num_comprobante` varchar(255) DEFAULT null,
  `fecha_pago` datetime NOT NULL,
  `razon` varchar(255) DEFAULT null,
  `nit` varchar(255) DEFAULT null,
  `autorizacion` varchar(255) DEFAULT null,
  `valido` varchar(255) DEFAULT null,
  `concepto` varchar(255) DEFAULT null,
  `codigo_control` varchar(255) DEFAULT null,
  `code_tipo_pago` varchar(255) DEFAULT null,
  `numero_cuenta` varchar(255) DEFAULT null,
  `nro_deposito` varchar(255) DEFAULT null,
  `fecha_deposito` date DEFAULT null,
  `nro_nota` varchar(255) DEFAULT null,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `migrations` (
  `id` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL
);

CREATE TABLE `cobro` (
  `cod_ceta` varchar(255) NOT NULL,
  `cod_pensum` varchar(255) NOT NULL,
  `tipo_inscripcion` varchar(255) NOT NULL,
  `cuota_id` bigint DEFAULT null,
  `nro_cobro` int NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `fecha_cobro` date NOT NULL,
  `cobro_completo` bool DEFAULT null,
  `observaciones` text,
  `id_usuario` bigint NOT NULL,
  `id_formadeCobro` varchar(255) NOT NULL,
  `pu_mensualidad` decimal(10,2) NOT NULL,
  `order` int(2) NOT NULL,
  `descuento` varchar(255) DEFAULT null,
  `id_cuenta_bancaria` bigint DEFAULT null,
  `id_factura` bigint DEFAULT null,
  `id_recibo` bigint DEFAULT null,
  `id_item_service` int DEFAULT null,
  `id_asignacion_costo` int DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`cod_ceta`, `cod_pensum`, `tipo_inscripcion`, `nro_cobro`)
);

CREATE TABLE `cobros_detalle_regular` (
  `nro_cobro` int PRIMARY KEY NOT NULL,
  `cod_inscrip` bigint NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `cobros_detalle_multa` (
  `nro_cobro` int PRIMARY KEY NOT NULL,
  `gestion` varchar(255) NOT NULL,
  `dias_multa` int NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) PRIMARY KEY NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT null
);

CREATE TABLE `pensums` (
  `cod_pensum` varchar(50) PRIMARY KEY NOT NULL,
  `codigo_carrera` varchar(50) NOT NULL,
  `nombre` varchar(40) NOT NULL,
  `descripcion` text DEFAULT null,
  `cantidad_semestres` int DEFAULT null,
  `orden` int DEFAULT null,
  `nivel` text DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `carrera` (
  `codigo_carrera` varchar(50) PRIMARY KEY NOT NULL,
  `nombre` varchar(40) NOT NULL,
  `descripcion` text DEFAULT null,
  `prefijo_matricula` varchar(10) DEFAULT null,
  `callback` varchar(150) DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `productos` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `descripcion` mediumtext NOT NULL,
  `precio` double NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `prorrogas` (
  `id_prorroga` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint NOT NULL,
  `cod_ceta` varchar(255) NOT NULL,
  `cuota_id` bigint DEFAULT null,
  `fecha_solicitud` date NOT NULL,
  `fecha_prorroga` date NOT NULL,
  `estado` varchar(255) NOT NULL,
  `motivo` text,
  `observaciones` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `sessions` (
  `id` varchar(255) PRIMARY KEY NOT NULL,
  `user_id` bigint DEFAULT null,
  `ip_address` varchar(45) DEFAULT null,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL
);

CREATE TABLE `sincronizaciones` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `tipo` varchar(255) NOT NULL,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime DEFAULT null,
  `estado` varchar(255) NOT NULL,
  `detalle` text,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `parametros_factura` (
  `id_parametrosFactura` tinyint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nit_emisor` varchar(50) NOT NULL,
  `razonSocialEmisor` varchar(50) NOT NULL,
  `municipio` text DEFAULT null,
  `telefono` int NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `sucursal` (
  `codigo_sucursal` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `direccion` varchar(50) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `item_service` (
  `id_item_service` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `codigo_producto_impuestos` int NOT NULL,
  `codigo_producto_interno` varchar(50) NOT NULL,
  `codigo_producto_economico` varchar(255) DEFAULT null,
  `unidad_medida` int NOT NULL,
  `nombre_servicio` int NOT NULL,
  `facturado` bool NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `users` (
  `id` bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp DEFAULT null,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `usuarios` (
  `id_usuario` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nickname` varchar(40) NOT NULL,
  `nombre` varchar(30) NOT NULL,
  `ap_paterno` varchar(40) NOT NULL,
  `ap_materno` varchar(40) NOT NULL,
  `contrasenia` varchar(30) NOT NULL,
  `ci` varchar(25) NOT NULL,
  `estado` bool DEFAULT null,
  `id_rol` varchar(40) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `datos_mora` (
  `id_datos_mora` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `gestion` varchar(50) NOT NULL,
  `num_mensualida_mora` int NOT NULL,
  `mes_mora` varchar(50) NOT NULL,
  `monto_mora` int NOT NULL,
  `fecha_inicio_multa` date NOT NULL,
  `fecha_fin_multa` date NOT NULL,
  `concepto` varchar(5,2) DEFAULT null,
  `estado` bool NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `datos_mora_detalle` (
  `id_datos_mora_detalle` int NOT NULL AUTO_INCREMENT,
  `id_datos_mora` int NOT NULL,
  `semestre` varchar(50) NOT NULL,
  `fecha_inicio_multa` date NOT NULL,
  `fecha_fin_multa` date NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_datos_mora_detalle`, `id_datos_mora`)
);

CREATE TABLE `parametros_economicos` (
  `id_parametro_economico` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(20) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `estado` bool NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_parametro_economico`, `nombre`)
);

CREATE TABLE `items_cobro` (
  `id_item` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `codigo_producto_impuesto` int DEFAULT null,
  `codigo_producto_interno` varchar(15) NOT NULL,
  `unidad_medida` int NOT NULL,
  `nombre_servicio` varchar(100) NOT NULL,
  `nro_creditos` decimal(10,2) NOT NULL,
  `costo` int DEFAULT null,
  `facturado` bool NOT NULL,
  `actividad_economica` varchar(255) NOT NULL,
  `descripcion` text DEFAULT null,
  `tipo_item` varchar(40) NOT NULL,
  `estado` bool,
  `id_parametro_economico` int NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `materia` (
  `sigla_materia` varchar(255) NOT NULL,
  `cod_pensum` varchar(50) NOT NULL,
  `nombre_materia` varchar(50) NOT NULL,
  `nombre_material_oficial` varchar(50) NOT NULL,
  `estado` bool NOT NULL,
  `orden` int NOT NULL,
  `descripcion` text DEFAULT null,
  `id_parametro_economico` int NOT NULL,
  `nro_creditos` decimal(10,2) NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`sigla_materia`, `cod_pensum`)
);

CREATE TABLE `kardex_notas` (
  `cod_ceta` int NOT NULL AUTO_INCREMENT,
  `cod_pensum` varchar(50) NOT NULL,
  `cod_inscrip` int NOT NULL,
  `tipo_incripcion` varchar(50) NOT NULL,
  `cod_kardex` int NOT NULL,
  `sigla_materia` varchar(30) NOT NULL,
  `observacion` varchar(50) NOT NULL,
  `id_usuario` int NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`cod_ceta`, `cod_pensum`, `cod_inscrip`, `tipo_incripcion`, `cod_kardex`)
);

CREATE TABLE `costo_semestral` (
  `id_costo_semestral` bigint NOT NULL AUTO_INCREMENT,
  `cod_pensum` varchar(50) NOT NULL,
  `gestion` varchar(30) NOT NULL,
  `cod_inscrip` bigint,
  `semestre` varchar(30) NOT NULL,
  `monto_semestre` decimal(10,2) NOT NULL,
  `id_usuario` bigint NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_costo_semestral`, `cod_pensum`, `gestion`)
);

CREATE TABLE `costo_materia` (
  `id_costo_materia` bigint NOT NULL AUTO_INCREMENT,
  `sigla_materia` varchar(255) NOT NULL,
  `gestion` varchar(30) NOT NULL,
  `nro_creditos` decimal(10,2) NOT NULL,
  `nombre_materia` varchar(30) NOT NULL,
  `monto_materia` decimal(10,2) DEFAULT null,
  `id_usuario` bigint NOT NULL,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_costo_materia`, `sigla_materia`, `gestion`)
);

CREATE TABLE `asignacion_costos` (
  `cod_pensum` varchar(50) NOT NULL,
  `cod_inscrip` bigint NOT NULL,
  `id_asignacion_costo` bigint AUTO_INCREMENT,
  `monto` decimal(10,2) NOT NULL,
  `observaciones` text,
  `estado` bool,
  `id_costo_semestral` bigint NOT NULL,
  `id_descuentoDetalle` varchar(255) DEFAULT null,
  `id_prorroga` int DEFAULT null,
  `id_compromisos` int DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`cod_pensum`, `cod_inscrip`, `id_asignacion_costo`)
);

CREATE TABLE `recargo_mora` (
  `id_recargo_mora` int NOT NULL AUTO_INCREMENT,
  `id_asignacion_costo` bigint AUTO_INCREMENT,
  `dias_mora` tinyint NOT NULL,
  `fecha_inicio_mora` date NOT NULL,
  `observaciones` text,
  `estado` bool,
  `id_datos_mora_detalle` int NOT NULL,
  `id_prorroga` int DEFAULT null,
  `id_compromisos` int DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_recargo_mora`, `id_asignacion_costo`)
);

CREATE TABLE `rol` (
  `id_rol` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `descripcion` varchar(30) DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `funciones` (
  `id_funcion` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `nombre` varchar(30) NOT NULL,
  `descripcion` varchar(60) DEFAULT null,
  `estado` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `asignacion_funcion` (
  `id_funcion` int NOT NULL,
  `id_usuario` int NOT NULL,
  `fecha_ini` date NOT NULL,
  `fecha_fin` date DEFAULT null,
  `usuario_asig` bool DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null,
  PRIMARY KEY (`id_funcion`, `id_usuario`)
);

CREATE TABLE `qr_transacciones` (
  `id_qr_transaccion` int PRIMARY KEY NOT NULL,
  `id_usuario` int NOT NULL,
  `nro_factura` int DEFAULT null,
  `anio` int DEFAULT null,
  `nro_recibo` int DEFAULT null,
  `anio_recibo` int DEFAULT null,
  `id_cuenta_bancaria` varchar(30) NOT NULL,
  `alias` varchar(50),
  `codigo_qr` int NOT NULL,
  `cod_ceta` int NOT NULL,
  `cod_pensum` varchar(50) NOT NULL,
  `tipo_inscripcion` varchar(20) NOT NULL,
  `id_cuota` int DEFAULT null,
  `id_forma_cobro` varchar(10) NOT NULL,
  `monto_total` decimal(10,2) NOT NULL,
  `moneda` varchar(3),
  `estado` enum(generado,escaneado,procesando,completado,expirado,cancelado) DEFAULT 'generado',
  `detalle_glosa` varchar(30),
  `fecha_generacion` timestamp DEFAULT 'current_timestamp',
  `fecha_expiracion` timestamp NOT NULL,
  `nro_autorizacion` varchar(100) DEFAULT null,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `qr_conceptos_detalle` (
  `id_qr_conceptos_detalle` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `id_qr_transaccion` int NOT NULL,
  `tipo_concepto` varchar(50) NOT NULL,
  `nro_cobro` int DEFAULT null,
  `concepto` varchar(50) NOT NULL,
  `observaciones` text,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `orden` int DEFAULT 1,
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `qr_estados_log` (
  `id_qr_estado_log` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `id_qr_transaccion` int NOT NULL,
  `estado_anterior` varchar(20),
  `estado_nuevo` varchar(20) NOT NULL,
  `motivo_cambio` varchar(20) NOT NULL,
  `usuario` varchar(50),
  `fecha_cambio` timestamp DEFAULT 'current_timestamp',
  `created_at` timestamp DEFAULT null,
  `updated_at` timestamp DEFAULT null
);

CREATE TABLE `qr_respuestas_banco` (
  `id_respuesta_banco` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `id_qr_transaccion` int NOT NULL,
  `banco` varchar(50) NOT NULL,
  `codigo_respuesta` varchar(20),
  `mensaje_respuesta` text,
  `numero_autorizacion` varchar(100),
  `numero_referencia` varchar(100),
  `numero_comprobante` varchar(100),
  `fecha_respuesta` timestamp DEFAULT 'current_timestamp'
);

CREATE TABLE `qr_configuracion` (
  `id_qr_config` int PRIMARY KEY NOT NULL AUTO_INCREMENT,
  `cod_pensum` varchar(50) DEFAULT null,
  `tiempo_expiracion_minutos` int DEFAULT 1440,
  `monto_minimo` decimal(10,2) DEFAULT 200,
  `permite_pago_parcial` bool DEFAULT false,
  `template_mensaje` text,
  `estado` bool DEFAULT true
);

CREATE INDEX `compromisos_usuario_id_foreign` ON `compromisos` (`usuario_id`) USING BTREE;

CREATE INDEX `costo_aplicado_costo_id_foreign` ON `costo_detalle` (`costo_id`) USING BTREE;

CREATE INDEX `descuento_usuario_descuento_id_foreign` ON `descuento_detalle` (`id_descuento`) USING BTREE;

CREATE INDEX `descuento_usuario_usuario_id_foreign` ON `descuento_detalle` (`id_usuario`) USING BTREE;

CREATE INDEX `descuento_usuario_inscripcion_id_foreign` ON `descuento_detalle` (`id_inscripcion`) USING BTREE;

CREATE INDEX `descuento_usuario_cuota_id_foreign` ON `descuento_detalle` (`id_cuota`) USING BTREE;

CREATE INDEX `egresos_usuario_id_foreign` ON `egresos` (`usuario_id`) USING BTREE;

CREATE UNIQUE INDEX `failed_jobs_uuid_unique` ON `failed_jobs` (`uuid`) USING BTREE;

CREATE INDEX `historial_observaciones_cod_ceta_foreign` ON `historial_observaciones` (`cod_ceta`);

CREATE INDEX `historial_observaciones_inscripcion_pensum_foreign` ON `historial_observaciones` (`cod_inscrip`, `cod_pensum`);

CREATE INDEX `ingresos_usuario_id_foreign` ON `otros_ingresos` (`usuario_id`) USING BTREE;

CREATE INDEX `inscripciones_usuario_id_foreign` ON `inscripciones` (`id_usuario`) USING BTREE;

CREATE INDEX `inscripciones_pensum_id_foreign` ON `inscripciones` (`cod_pensum`) USING BTREE;

CREATE INDEX `inscripciones_cod_ceta_foreign` ON `inscripciones` (`cod_ceta`) USING BTREE;

CREATE INDEX `jobs_queue_index` ON `jobs` (`queue`) USING BTREE;

CREATE INDEX `logs_usuario_id_foreign` ON `logs` (`usuario_id`) USING BTREE;

CREATE INDEX `matriculas_usuario_id_foreign` ON `matriculas` (`usuario_id`) USING BTREE;

CREATE INDEX `matriculas_inscripcion_id_foreign` ON `matriculas` (`cod_inscrip`) USING BTREE;

CREATE INDEX `pagos_usuario_id_foreign` ON `cobro` (`id_usuario`) USING BTREE;

CREATE INDEX `pagos_cuota_id_foreign` ON `cobro` (`cuota_id`) USING BTREE;

CREATE INDEX `pagos_forma_pago_id_foreign` ON `cobro` (`id_formadeCobro`) USING BTREE;

CREATE INDEX `pagos_cuenta_bancaria_id_foreign` ON `cobro` (`id_cuenta_bancaria`) USING BTREE;

CREATE INDEX `prorrogas_usuario_id_foreign` ON `prorrogas` (`usuario_id`) USING BTREE;

CREATE INDEX `prorrogas_cuota_id_foreign` ON `prorrogas` (`cuota_id`) USING BTREE;

CREATE INDEX `sessions_user_id_index` ON `sessions` (`user_id`) USING BTREE;

CREATE INDEX `sessions_last_activity_index` ON `sessions` (`last_activity`) USING BTREE;

CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`) USING BTREE;

ALTER TABLE `compromisos` ADD CONSTRAINT `compromisos_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_detalle` ADD CONSTRAINT `costo_aplicado_costo_id_foreign` FOREIGN KEY (`costo_id`) REFERENCES `costos` (`cod_costo`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuento_detalle` ADD CONSTRAINT `descuento_usuario_cuota_id_foreign` FOREIGN KEY (`id_cuota`) REFERENCES `cuotas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuento_detalle` ADD CONSTRAINT `descuento_usuario_descuento_id_foreign` FOREIGN KEY (`id_descuento`) REFERENCES `descuentos` (`id_descuentos`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuento_detalle` ADD CONSTRAINT `descuento_usuario_inscripcion_id_foreign` FOREIGN KEY (`id_inscripcion`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuento_detalle` ADD CONSTRAINT `descuento_usuario_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `egresos` ADD CONSTRAINT `egresos_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `historial_observaciones` ADD CONSTRAINT `historial_observaciones_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `otros_ingresos` ADD CONSTRAINT `ingresos_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `inscripciones` ADD CONSTRAINT `inscripciones_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `inscripciones` ADD CONSTRAINT `inscripciones_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `logs` ADD CONSTRAINT `logs_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `matriculas` ADD CONSTRAINT `matriculas_inscripcion_id_foreign` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `matriculas` ADD CONSTRAINT `matriculas_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `pagos_cuenta_bancaria_id_foreign` FOREIGN KEY (`id_cuenta_bancaria`) REFERENCES `cuentas_bancarias` (`id_cuentas_bancarias`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `pagos_cuota_id_foreign` FOREIGN KEY (`cuota_id`) REFERENCES `cuotas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `pagos_forma_pago_id_foreign` FOREIGN KEY (`id_formadeCobro`) REFERENCES `formas_cobro` (`id_forma_cobro`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `pagos_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `prorrogas` ADD CONSTRAINT `prorrogas_cuota_id_foreign` FOREIGN KEY (`cuota_id`) REFERENCES `cuotas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `prorrogas` ADD CONSTRAINT `prorrogas_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `inscripciones` ADD CONSTRAINT `inscripciones_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `historial_observaciones` ADD CONSTRAINT `historial_observaciones_inscripcion_fk` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `historial_observaciones` ADD CONSTRAINT `historial_observaciones_pensum_fk` FOREIGN KEY (`cod_pensum`) REFERENCES `inscripciones` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `otros_ingresos` ADD CONSTRAINT `ingresos_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `prorrogas` ADD CONSTRAINT `prorrogas_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `pagos_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `matriculas` ADD CONSTRAINT `matriculas_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `compromisos` ADD CONSTRAINT `compromisos_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `matriculas` ADD CONSTRAINT `matriculas_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costos` ADD CONSTRAINT `costos_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costos` ADD CONSTRAINT `costos_gestion_id_foreign` FOREIGN KEY (`gestion`) REFERENCES `gestion` (`gestion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `inscripciones` ADD CONSTRAINT `inscripciones_gestion_id_foreign` FOREIGN KEY (`gestion`) REFERENCES `gestion` (`gestion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `cobro_recibo_id_foreign` FOREIGN KEY (`id_recibo`) REFERENCES `recibo` (`nro_recibo`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `cobro_factura_id_foreign` FOREIGN KEY (`id_factura`) REFERENCES `factura` (`nro_factura`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costos` ADD CONSTRAINT `costos_descuento_id_foreign` FOREIGN KEY (`id_descuento`) REFERENCES `descuentos` (`id_descuentos`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuentos` ADD CONSTRAINT `descuentos_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuentos` ADD CONSTRAINT `descuentos_cod_pensum_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuentos` ADD CONSTRAINT `descuentos_cod_inscrip_foreign` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `descuentos` ADD CONSTRAINT `descuentos_id_usuario_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `factura` ADD CONSTRAINT `parametros_factura_id_parametros_factura_foreign` FOREIGN KEY (`id_parametros_factura`) REFERENCES `parametros_factura` (`id_parametrosFactura`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `factura` ADD CONSTRAINT `sucursal_codigo_sucursal_factura_foreign` FOREIGN KEY (`codigo_sucursal`) REFERENCES `sucursal` (`codigo_sucursal`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `cobros_id_items_service_id_foreign` FOREIGN KEY (`id_item_service`) REFERENCES `item_service` (`id_item_service`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `nota_respaldo` ADD CONSTRAINT `nota_respaldo_recibo_id_foreign` FOREIGN KEY (`nro_recibo`) REFERENCES `recibo` (`nro_recibo`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `nota_respaldo` ADD CONSTRAINT `nota_respaldo_factura_id_foreign` FOREIGN KEY (`nro_factura`) REFERENCES `factura` (`nro_factura`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_detalle` ADD CONSTRAINT `costo_detalle_usuarios_id_foreign` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `datos_mora_detalle` ADD CONSTRAINT `datos_mora_detalle_datos_mora_id_foreign` FOREIGN KEY (`id_datos_mora`) REFERENCES `datos_mora` (`id_datos_mora`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `materia` ADD CONSTRAINT `materia_parametros_economicos_id_foreign` FOREIGN KEY (`id_parametro_economico`) REFERENCES `parametros_economicos` (`id_parametro_economico`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `items_cobro` ADD CONSTRAINT `items_cobro_parametros_economicos_id_foreign` FOREIGN KEY (`id_parametro_economico`) REFERENCES `parametros_economicos` (`id_parametro_economico`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `materia` ADD CONSTRAINT `materia_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `kardex_notas` ADD CONSTRAINT `kardex_notas_inscripcion_id_foreign` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `historial_observaciones` ADD FOREIGN KEY (`observacion`) REFERENCES `historial_observaciones` (`tipo_observacion`);

ALTER TABLE `kardex_notas` ADD CONSTRAINT `kardex_notas_cod_ceta_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `kardex_notas` ADD CONSTRAINT `kardex_notas_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `kardex_notas` ADD CONSTRAINT `kardex_notas_materia_id_foreign` FOREIGN KEY (`sigla_materia`) REFERENCES `materia` (`sigla_materia`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `kardex_notas` ADD CONSTRAINT `kardex_nota_usuarios_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_semestral` ADD CONSTRAINT `costo_semestral_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_semestral` ADD CONSTRAINT `costo_semestral_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_semestral` ADD CONSTRAINT `costo_semestral_gestion_id_foreign` FOREIGN KEY (`gestion`) REFERENCES `gestion` (`gestion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_semestral` ADD CONSTRAINT `costo_semestral_inscripcion_id_foreign` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_pensum_id_foreign` FOREIGN KEY (`cod_pensum`) REFERENCES `pensums` (`cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_inscripcion_id_foreign` FOREIGN KEY (`cod_inscrip`) REFERENCES `inscripciones` (`cod_inscrip`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_costo_semestral_id_foreign` FOREIGN KEY (`id_costo_semestral`) REFERENCES `costo_semestral` (`id_costo_semestral`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_descuento_detalle_id_foreign` FOREIGN KEY (`id_descuentoDetalle`) REFERENCES `descuento_detalle` (`id_descuento_detalle`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_prorroga_id_foreign` FOREIGN KEY (`id_prorroga`) REFERENCES `prorrogas` (`id_prorroga`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_costos` ADD CONSTRAINT `asignacion_costos_compromisos_id_foreign` FOREIGN KEY (`id_compromisos`) REFERENCES `compromisos` (`id_compromisos`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recargo_mora` ADD CONSTRAINT `recargo_mora_asignacion_costo_id_foreign` FOREIGN KEY (`id_asignacion_costo`) REFERENCES `asignacion_costos` (`id_asignacion_costo`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recargo_mora` ADD CONSTRAINT `recargo_mora_datos_mora_id_foreign` FOREIGN KEY (`id_datos_mora_detalle`) REFERENCES `datos_mora_detalle` (`id_datos_mora_detalle`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recargo_mora` ADD CONSTRAINT `recargo_mora_prorroga_id_foreign` FOREIGN KEY (`id_prorroga`) REFERENCES `prorrogas` (`id_prorroga`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recargo_mora` ADD CONSTRAINT `recargo_mora_compromisos_id_foreign` FOREIGN KEY (`id_compromisos`) REFERENCES `compromisos` (`id_compromisos`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `cobro` ADD CONSTRAINT `cobro_asignacion_costo_id_foreign` FOREIGN KEY (`id_asignacion_costo`) REFERENCES `asignacion_costos` (`id_asignacion_costo`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recibo` ADD CONSTRAINT `recibo_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `recibo` ADD CONSTRAINT `pagos_forma_recibo_id_foreign` FOREIGN KEY (`id_forma_cobro`) REFERENCES `formas_cobro` (`id_forma_cobro`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `usuarios` ADD CONSTRAINT `usuarios_usuario_rol_id_foreign` FOREIGN KEY (`id_rol`) REFERENCES `rol` (`id_rol`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_funcion` ADD CONSTRAINT `usuario_funcion_usuario_id_foreign` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `asignacion_funcion` ADD CONSTRAINT `funciones_funcion_usuario_id_foreign` FOREIGN KEY (`id_funcion`) REFERENCES `funciones` (`id_funcion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `qr_transacciones` ADD CONSTRAINT `qr_transacciones_estudiantes_id_foreign` FOREIGN KEY (`cod_ceta`) REFERENCES `estudiantes` (`cod_ceta`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `qr_transacciones` ADD CONSTRAINT `qr_transacciones_forma_cobro_id_foreign` FOREIGN KEY (`id_forma_cobro`) REFERENCES `formas_cobro` (`id_forma_cobro`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `qr_conceptos_detalle` ADD CONSTRAINT `qr_conceptos_detalle_qr_transacciones_id_foreign` FOREIGN KEY (`id_qr_transaccion`) REFERENCES `qr_transacciones` (`id_qr_transaccion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `qr_estados_log` ADD CONSTRAINT `qr_estados_log_qr_transacciones_id_foreign` FOREIGN KEY (`id_qr_transaccion`) REFERENCES `qr_transacciones` (`id_qr_transaccion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `qr_respuestas_banco` ADD CONSTRAINT `qr_respuestas_banco_qr_transacciones_id_foreign` FOREIGN KEY (`id_qr_transaccion`) REFERENCES `qr_transacciones` (`id_qr_transaccion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_materia` ADD CONSTRAINT `costo_materia_gestion_materia_id_foreign` FOREIGN KEY (`sigla_materia`) REFERENCES `materia` (`sigla_materia`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `costo_materia` ADD CONSTRAINT `costo_materia_gestion_id_foreign` FOREIGN KEY (`gestion`) REFERENCES `gestion` (`gestion`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `pensums` ADD CONSTRAINT `carrera_pensum_id_foreign` FOREIGN KEY (`codigo_carrera`) REFERENCES `carrera` (`codigo_carrera`) ON DELETE RESTRICT ON UPDATE RESTRICT;
