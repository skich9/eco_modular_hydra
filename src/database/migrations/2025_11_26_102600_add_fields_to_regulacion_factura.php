<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToRegulacionFactura extends Migration {
	public function up()
	{
		Schema::table('regulacion_factura', function (Blueprint $table) {
			// Descripción del intento de regularización
			if (!Schema::hasColumn('regulacion_factura', 'descripcion')) {
				$table->text('descripcion')->nullable()->after('codigo_evento_significativo');
			}
			
			// Fecha en que se realizó la regularización
			if (!Schema::hasColumn('regulacion_factura', 'fecha_regularizacion')) {
				$table->timestamp('fecha_regularizacion')->nullable()->after('descripcion');
			}
			
			// Contexto de la regularización
			if (!Schema::hasColumn('regulacion_factura', 'codigo_cuis')) {
				$table->string('codigo_cuis', 50)->nullable()->after('fecha_regularizacion');
			}
			
			if (!Schema::hasColumn('regulacion_factura', 'codigo_punto_venta')) {
				$table->string('codigo_punto_venta', 25)->nullable()->after('codigo_cuis');
			}
			
			if (!Schema::hasColumn('regulacion_factura', 'codigo_sucursal')) {
				$table->integer('codigo_sucursal')->nullable()->after('codigo_punto_venta');
			}
			
			// Resultado de la transacción
			if (!Schema::hasColumn('regulacion_factura', 'transaccion')) {
				$table->boolean('transaccion')->default(false)->after('codigo_sucursal');
			}
			
			if (!Schema::hasColumn('regulacion_factura', 'resultado_esperado')) {
				$table->text('resultado_esperado')->nullable()->after('transaccion');
			}
			
			if (!Schema::hasColumn('regulacion_factura', 'errores')) {
				$table->text('errores')->nullable()->after('resultado_esperado');
			}
			
			// Tipo de regularización
			if (!Schema::hasColumn('regulacion_factura', 'es_manual')) {
				$table->boolean('es_manual')->default(false)->after('errores');
			}
			
			// Código de recepción del paquete
			if (!Schema::hasColumn('regulacion_factura', 'codigo_recepcion_paquete')) {
				$table->string('codigo_recepcion_paquete', 200)->nullable()->after('es_manual');
			}
			
			// Número de archivo del paquete
			if (!Schema::hasColumn('regulacion_factura', 'nro_archivo')) {
				$table->integer('nro_archivo')->nullable()->after('codigo_recepcion_paquete');
			}
			
			// Estado de la regularización
			if (!Schema::hasColumn('regulacion_factura', 'estado')) {
				$table->string('estado', 100)->nullable()->after('nro_archivo');
			}
			
			// Índices para búsquedas
			$table->index(['nro_factura', 'anio'], 'idx_regulacion_factura');
			$table->index(['codigo_recepcion_paquete'], 'idx_regulacion_codigo_recepcion');
			$table->index(['fecha_regularizacion'], 'idx_regulacion_fecha');
		});
	}

	public function down()
	{
		Schema::table('regulacion_factura', function (Blueprint $table) {
			$table->dropIndex('idx_regulacion_factura');
			$table->dropIndex('idx_regulacion_codigo_recepcion');
			$table->dropIndex('idx_regulacion_fecha');
			
			$table->dropColumn([
				'descripcion',
				'fecha_regularizacion',
				'codigo_cuis',
				'codigo_punto_venta',
				'codigo_sucursal',
				'transaccion',
				'resultado_esperado',
				'errores',
				'es_manual',
				'codigo_recepcion_paquete',
				'nro_archivo',
				'estado'
			]);
		});
	}
}
