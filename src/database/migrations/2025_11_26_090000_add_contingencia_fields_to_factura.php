<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContingenciaFieldsToFactura extends Migration {
	public function up()
	{
		Schema::table('factura', function (Blueprint $table) {
			// Tipo de emisión: 1=En línea, 2=Contingencia
			$table->integer('codigo_tipo_emision')->default(1)->after('tipo');

			// CAFC: Código de Autorización de Facturas en Contingencia
			$table->string('cafc', 50)->nullable()->after('cuf');

			// Evento significativo (si aplica)
			$table->integer('codigo_evento')->nullable()->after('cafc');
			$table->string('descripcion_evento', 255)->nullable()->after('codigo_evento');

			// Fecha de envío al SIN (para contingencias)
			$table->timestamp('fecha_envio')->nullable()->after('fecha_emision');

			// Mensaje de respuesta del SIN
			$table->text('mensaje_sin')->nullable()->after('estado');

			// Índices para búsquedas de contingencias
			$table->index(['codigo_tipo_emision', 'estado'], 'idx_factura_contingencia');
			$table->index(['codigo_evento'], 'idx_factura_evento');
		});
	}

	public function down()
	{
		Schema::table('factura', function (Blueprint $table) {
			$table->dropIndex('idx_factura_contingencia');
			$table->dropIndex('idx_factura_evento');
			$table->dropColumn([
				'codigo_tipo_emision',
				'cafc',
				'codigo_evento',
				'descripcion_evento',
				'fecha_envio',
				'mensaje_sin'
			]);
		});
	}
};
