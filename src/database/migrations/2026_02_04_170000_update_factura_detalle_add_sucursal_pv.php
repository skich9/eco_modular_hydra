<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('factura_detalle', function (Blueprint $table) {
			// Nuevas columnas para alinear con la PK lógica de factura
			$table->integer('codigo_sucursal')->default(0)->after('nro_factura');
			$table->string('codigo_punto_venta')->default('0')->after('codigo_sucursal');
		});

		// Ajustar claves: eliminar PK antigua y crear una nueva que incluya sucursal/pv
		Schema::table('factura_detalle', function (Blueprint $table) {
			$table->dropPrimary(['anio', 'nro_factura', 'id_detalle']);
			$table->primary(['anio', 'nro_factura', 'codigo_sucursal', 'codigo_punto_venta', 'id_detalle'], 'pk_factura_detalle_extendida');
		});
	}

	public function down(): void
	{
		Schema::table('factura_detalle', function (Blueprint $table) {
			$table->dropPrimary('pk_factura_detalle_extendida');
			$table->primary(['anio', 'nro_factura', 'id_detalle']);
			$table->dropColumn(['codigo_sucursal', 'codigo_punto_venta']);
		});
	}
};
