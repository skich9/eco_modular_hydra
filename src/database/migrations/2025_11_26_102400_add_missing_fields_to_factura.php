<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingFieldsToFactura extends Migration {
	public function up()
	{
		Schema::table('factura', function (Blueprint $table) {
			// Campos de control del SIN
			if (!Schema::hasColumn('factura', 'aceptado_impuestos')) {
				$table->boolean('aceptado_impuestos')->default(false)->after('estado');
			}
			
			if (!Schema::hasColumn('factura', 'eliminacion_factura')) {
				$table->boolean('eliminacion_factura')->default(false)->after('aceptado_impuestos');
			}
			
			if (!Schema::hasColumn('factura', 'codigo_excepcion')) {
				$table->integer('codigo_excepcion')->nullable()->after('codigo_recepcion');
			}
			
			// Campos de contexto de facturación
			if (!Schema::hasColumn('factura', 'codigo_doc_sector')) {
				$table->integer('codigo_doc_sector')->default(1)->after('codigo_tipo_emision');
			}
			
			if (!Schema::hasColumn('factura', 'periodo_facturado')) {
				$table->string('periodo_facturado', 50)->nullable()->after('fecha_emision');
			}
			
			if (!Schema::hasColumn('factura', 'es_manual')) {
				$table->boolean('es_manual')->default(false)->after('tipo');
			}
			
			// Índice para búsquedas de facturas aceptadas
			$table->index(['aceptado_impuestos', 'eliminacion_factura'], 'idx_factura_estado_sin');
		});
	}

	public function down()
	{
		Schema::table('factura', function (Blueprint $table) {
			$table->dropIndex('idx_factura_estado_sin');
			$table->dropColumn([
				'aceptado_impuestos',
				'eliminacion_factura',
				'codigo_excepcion',
				'codigo_doc_sector',
				'periodo_facturado',
				'es_manual'
			]);
		});
	}
}
