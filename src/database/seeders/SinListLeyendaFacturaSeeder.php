<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinListLeyendaFacturaSeeder extends Seeder
{
	public function run()
	{
		$rows = [
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: El proveedor de servicios debe habilitar medios e instrumentos para efectuar consultas y reclamaciones.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: El proveedor debe exhibir certificaciones de habilitación o documentos que acrediten las capacidades u ofertas de servicios especializados.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: El proveedor deberá suministrar el servicio en las modalidades y términos ofertados o convenidos.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: En caso de incumplimiento a lo ofertado o convenido, el proveedor debe reparar o sustituir el servicio.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: La interrupción del servicio debe comunicarse con anterioridad a las Autoridades que correspondan y a los usuarios afectados.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: Los servicios deben suministrarse en condiciones de inocuidad, calidad y seguridad.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: Tienes derecho a recibir información sobre las características y contenidos de los servicios que utilices.'],
			['codigo_caeb' => '853000', 'leyenda' => 'Ley N° 453: Tienes derecho a un trato equitativo sin discriminación en la oferta de servicios.'],
			['codigo_caeb' => '8530400', 'leyenda' => 'Ley N° 453: El proveedor debe brindar atención sin discriminación, con respeto, calidez y cordialidad a los usuarios y consumidores.'],
			['codigo_caeb' => '8530400', 'leyenda' => 'Ley N° 453: Puedes acceder a la reclamación cuando tus derechos han sido vulnerados. '],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: El proveedor de servicios debe habilitar medios e instrumentos para efectuar consultas y reclamaciones.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: El proveedor debe exhibir certificaciones de habilitación o documentos que acrediten las capacidades u ofertas de servicios especializados.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: El proveedor deberá suministrar el servicio en las modalidades y términos ofertados o convenidos.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: En caso de incumplimiento a lo ofertado o convenido, el proveedor debe reparar o sustituir el servicio.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: La interrupción del servicio debe comunicarse con anterioridad a las Autoridades que correspondan y a los usuarios afectados.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: Los servicios deben suministrarse en condiciones de inocuidad, calidad y seguridad.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: Tienes derecho a recibir información sobre las características y contenidos de los servicios que utilices.'],
			['codigo_caeb' => '854000', 'leyenda' => 'Ley N° 453: Tienes derecho a un trato equitativo sin discriminación en la oferta de servicios.'],
		];

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('sin_list_leyenda_factura')->truncate();
		DB::table('sin_list_leyenda_factura')->insert($rows);
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
