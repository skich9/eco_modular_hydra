<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		// sin_punto_venta
		if (!Schema::hasTable('sin_punto_venta')) {
			Schema::create('sin_punto_venta', function (Blueprint $table) {
				$table->string('codigo_punto_venta', 50)->primary();
				$table->string('nombre', 200);
				$table->text('descripcion');
				$table->integer('sucursal'); // FK opcional a sucursal.codigo_sucursal
				$table->string('codigo_cuis_genera', 50); // referencia lógica a sin_cuis.codigo_cuis (evitar FK cruzado para no ciclar)
				$table->text('id_usuario_crea');
				$table->integer('tipo');
				$table->string('ip', 50);
				$table->boolean('activo')->nullable();
				$table->timestamp('fecha_creacion')->nullable();
				$table->boolean('crear_cufd');
			});
			// Sin FKs adicionales en este punto según SQL fuente
		}

		// sin_cuis (FK a sucursal y opcional a sin_punto_venta)
		if (!Schema::hasTable('sin_cuis')) {
			Schema::create('sin_cuis', function (Blueprint $table) {
				$table->string('codigo_cuis', 50)->primary();
				$table->timestamp('fecha_vigencia');
				$table->integer('codigo_sucursal');
				$table->string('codigo_punto_venta', 25)->nullable();
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_cufd (FK a sin_cuis y opcional a sucursal y sin_punto_venta)
		if (!Schema::hasTable('sin_cufd')) {
			Schema::create('sin_cufd', function (Blueprint $table) {
				$table->string('codigo_cufd', 100)->primary();
				$table->string('codigo_control', 50);
				$table->text('direccion');
				$table->timestamp('fecha_vigencia');
				$table->string('codigo_cuis', 50);
				$table->string('codigo_punto_venta', 25)->nullable();
				$table->integer('codigo_sucursal')->nullable();
				$table->float('diferencia_tiempo', 24)->nullable();
				$table->timestamp('fecha_inicio')->nullable();
				$table->timestamp('fecha_fin')->nullable();
			});
			// Agregar solo la FK definida en el SQL fuente
			Schema::table('sin_cufd', function (Blueprint $table) {
				$table->foreign('codigo_cuis')
					->references('codigo_cuis')
					->on('sin_cuis')
					->restrictOnDelete()
					->restrictOnUpdate();
			});
		}

		// sin_cafc
		if (!Schema::hasTable('sin_cafc')) {
			Schema::create('sin_cafc', function (Blueprint $table) {
				$table->integer('codigo_cafc')->primary();
				$table->string('cafc', 50)->nullable();
				$table->date('fecha_creacion')->nullable();
				$table->integer('num_minimo')->nullable();
				$table->integer('num_maximo')->nullable();
			});
		}

		// sin_evento_significativo
		if (!Schema::hasTable('sin_evento_significativo')) {
			Schema::create('sin_evento_significativo', function (Blueprint $table) {
				$table->integer('id_evento')->primary();
				$table->integer('codigo_recepcion');
				$table->timestamp('fecha_inicio');
				$table->timestamp('fecha_fin');
				$table->integer('codigo_evento');
				$table->integer('codigo_sucursal')->nullable();
				$table->string('codigo_punto_venta', 25)->nullable();
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_recepcion_paquete_factura
		if (!Schema::hasTable('sin_recepcion_paquete_factura')) {
			Schema::create('sin_recepcion_paquete_factura', function (Blueprint $table) {
				$table->integer('id_recepcion')->primary();
				$table->string('descripcion', 200)->nullable();
				$table->string('estado', 50)->nullable();
				$table->string('codigo_recepcion', 50)->nullable();
				$table->text('facturas')->nullable();
				$table->text('nombre_salida')->nullable();
				$table->text('mensajes_list')->nullable();
				$table->timestamp('fecha_registro')->nullable();
			});
		}

		// sin_usuario_punto_venta
		if (!Schema::hasTable('sin_usuario_punto_venta')) {
			Schema::create('sin_usuario_punto_venta', function (Blueprint $table) {
				$table->string('id_usuario', 255);
				$table->string('codigo_punto_venta', 50);
				$table->timestamp('fecha_asignacion')->nullable();
				$table->text('quien_asigna');
				$table->boolean('activo');
				$table->primary(['id_usuario', 'codigo_punto_venta']);
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_list_actividad_doc_sector
		if (!Schema::hasTable('sin_list_actividad_doc_sector')) {
			Schema::create('sin_list_actividad_doc_sector', function (Blueprint $table) {
				$table->string('codigo_actividad', 25);
				$table->string('codigo_documento_sector', 25);
				$table->string('tipo_documento_sector', 25);
				$table->primary(['codigo_actividad', 'codigo_documento_sector']);
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_list_leyenda_factura
		if (!Schema::hasTable('sin_list_leyenda_factura')) {
			Schema::create('sin_list_leyenda_factura', function (Blueprint $table) {
				$table->string('codigo_actividad', 25);
				$table->string('descripcion_leyenda', 255);
				$table->primary(['codigo_actividad', 'descripcion_leyenda']);
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_list_producto_servicio
		if (!Schema::hasTable('sin_list_producto_servicio')) {
			Schema::create('sin_list_producto_servicio', function (Blueprint $table) {
				$table->string('codigo_actividad', 25);
				$table->string('codigo_producto', 25);
				$table->text('descripcion')->nullable();
				$table->primary(['codigo_actividad', 'codigo_producto']);
			});
			// Sin FKs: la estructura SQL compartida no define llaves foráneas
		}

		// sin_forma_cobro (relación lógica con formas_cobro.id_forma_cobro; sin FK por longitud diferente)
		if (!Schema::hasTable('sin_forma_cobro')) {
			Schema::create('sin_forma_cobro', function (Blueprint $table) {
				$table->integer('codigo_sin')->primary();
				$table->string('descripcion_sin', 200);
				$table->string('id_forma_cobro', 255);
				$table->boolean('activo');
				$table->timestamps();
			});
			// Agregar la FK según SQL fuente: sin_forma_cobro.id_forma_cobro -> formas_cobro.id_forma_cobro
			if (Schema::hasTable('formas_cobro')) {
				Schema::table('sin_forma_cobro', function (Blueprint $table) {
					$table->foreign('id_forma_cobro')
						->references('id_forma_cobro')
						->on('formas_cobro')
						->restrictOnDelete()
						->restrictOnUpdate();
				});
			}
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('sin_forma_cobro');
		Schema::dropIfExists('sin_list_producto_servicio');
		Schema::dropIfExists('sin_list_leyenda_factura');
		Schema::dropIfExists('sin_list_actividad_doc_sector');
		Schema::dropIfExists('sin_usuario_punto_venta');
		Schema::dropIfExists('sin_recepcion_paquete_factura');
		Schema::dropIfExists('sin_evento_significativo');
		Schema::dropIfExists('sin_cafc');
		Schema::dropIfExists('sin_cufd');
		Schema::dropIfExists('sin_cuis');
		Schema::dropIfExists('sin_punto_venta');
	}
};
