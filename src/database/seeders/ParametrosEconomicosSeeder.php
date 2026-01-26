<?php

namespace Database\Seeders;

use App\Models\ParametrosEconomicos;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ParametrosEconomicosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        // Crear parámetros económicos predefinidos
        $parametros = [
            [
                'nombre' => 'descuento_semestre_completo_activar',
                'valor' => 'true',
                'descripcion' => 'Indica al sistema si esta activo o inactivo el descuento de pago de semestre completo',
                'estado' => true,
            ],
            [
                'nombre' => 'descuento_semestre_completo_fecha_limite',
                'valor' => '2025-07-11',
                'descripcion' => 'Los cobros que se apliquen antes de la fecha pueden tener descuento de semestre completo si cumplen las condiciones',
                'estado' => true,
            ],
            [
                'nombre' => 'descuento_semestre_completo_porcentaje',
                'valor' => '10',
                'descripcion' => 'Debe tomar un valor entre 0 y 100, indica el porcentaje de descuento que se aplica cuando el estudiante paga todas las cuotas del semestre',
                'estado' => true,
            ],
            [
                'nombre' => 'dia_limite_pago',
                'valor' => '15',
                'descripcion' => 'Es el día del mes hasta donde puede realizar el pago sin ninguna multa o recargo',
                'estado' => true,
            ],
            [
                'nombre' => 'evaluacion_mensualidad',
                'valor' => '2',
                'descripcion' => 'Mensualidad a cobrar en el Sistema dependiendo la gestion 1 y 2 1 febrero- julio 2 marzo- agosto 3 abril- septiembre 4 mayo- octubre 5 junio- noviembre',
                'estado' => true,
            ],
            [
                'nombre' => 'facturacion_en_linea_API_estudiantes',
                'valor' => '/ceta/estudiantes/economico/FacturaController/recibirFactura',
                'descripcion' => 'Dirección de la API para enviar facturas a los estudiantes',
                'estado' => true,
            ],
            [
                'nombre' => 'facturacion_en_linea_estado',
                'valor' => 'activo',
                'descripcion' => 'Debe tomar valores que son: "activo","inactivo" que indican si se activa o desactiva la opción de pagar en línea de los estudiantes',
                'estado' => true,
            ],
            [
                'nombre' => 'facturacion_en_linea_punto_venta',
                'valor' => '23',
                'descripcion' => 'Número de punto de venta que usará la facturación automática',
                'estado' => true,
            ],
            [
                'nombre' => 'facturacion_en_linea_socket_IP',
                'valor' => 'mea.ceta.edu.bo:49000',
                'descripcion' => 'Debe tener tanto la IP como el puerto donde será levantando el servicio',
                'estado' => true,
            ],
            [
                'nombre' => 'facturacion_en_linea_usuario',
                'valor' => 'ServiciosOnline',
                'descripcion' => 'Nombre de Usuario para la Factura en Línea',
                'estado' => true,
            ],
            [
                'nombre' => 'facturar_offline',
                'valor' => 'inactivo',
                'descripcion' => 'Debe tomar valores que son: "activo","inactivo" que indican si se activa o desactiva la opcion facturar offline',
                'estado' => true,
            ],
            [
                'nombre' => 'gestion_cobro',
                'valor' => '2/2025',
                'descripcion' => 'Gestión activa de cobro actual',
                'estado' => true,
            ],
            [
                'nombre' => 'molinetes_socket_IP',
                'valor' => '192.168.0.130:49003',
                'descripcion' => 'Debe tener tanto la ip como el puerto donde esta levantado el servicio',
                'estado' => true,
            ],
            [
                'nombre' => 'multa_por_dia',
                'valor' => '1',
                'descripcion' => 'Es la cantidad en Bs que se cobra por día que supere la fecha límite de pago',
                'estado' => true,
            ],
            [
                'nombre' => 'tipo_factura_pdf',
                'valor' => '1',
                'descripcion' => 'Debe tomar valor 1 para tamaño Rollo y valor 2 para tamaño Carta',
                'estado' => true,
            ],
            [
                'nombre' => 'dinstitucionalmanana',
                'valor' => '51',
                'descripcion' => 'Id de descuento institucional del turno mañana',
                'estado' => true,
            ],
            [
                'nombre' => 'dinstitucionaltarde',
                'valor' => '52',
                'descripcion' => 'Id descuento institucional turno tarde',
                'estado' => true,
            ],
            [
                'nombre' => 'dinstitucionalnoche',
                'valor' => '53',
                'descripcion' => 'id descuento institucional turno noche',
                'estado' => true,
            ],
        ];

        foreach ($parametros as $parametro) {
            ParametrosEconomicos::create($parametro);
        }

        // Opcional: Crear parámetros económicos aleatorios adicionales si necesitas
        // ParametrosEconomicos::factory()->count(5)->create();
    }
}
