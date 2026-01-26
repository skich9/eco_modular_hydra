<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create('cobro', function (Blueprint $table) {
			$table->unsignedBigInteger('cod_ceta');
			$table->string('cod_pensum', 50);
			$table->string('tipo_inscripcion', 255);
			$table->unsignedBigInteger('cod_inscrip')->nullable();
			$table->unsignedBigInteger('id_cuota')->nullable();
			$table->string('gestion', 255)->nullable();
			$table->integer('nro_cobro');
			$table->integer('anio_cobro');
			$table->decimal('monto', 10, 2);
			$table->datetime('fecha_cobro');
			$table->boolean('cobro_completo')->nullable();
			$table->text('observaciones')->nullable();
			$table->text('concepto')->nullable();
			$table->string('cod_tipo_cobro', 50)->nullable();
			$table->unsignedBigInteger('id_usuario');
			$table->string('id_forma_cobro', 255);
			$table->char('tipo_documento', 1)->nullable();
			$table->char('medio_doc', 1)->nullable();
			$table->decimal('pu_mensualidad', 10, 4);
			$table->tinyInteger('order');
			$table->decimal('descuento', 10, 4)->nullable();
			$table->unsignedInteger('id_cuentas_bancarias')->nullable();
			$table->integer('nro_factura')->nullable();
			$table->integer('nro_recibo')->nullable();
			$table->integer('id_item')->nullable();
			$table->integer('id_asignacion_costo')->nullable();
			$table->string('qr_alias', 100)->nullable();
			$table->boolean('reposicion_factura')->default(false);
			$table->timestamps();

			$table->primary(['cod_ceta', 'cod_pensum', 'tipo_inscripcion', 'nro_cobro']);

			$table->index('nro_cobro');
			$table->index('fecha_cobro', 'idx_cobro_fecha_cobro');
			$table->index('cod_ceta', 'idx_cobro_cod_ceta');
			$table->index('nro_factura', 'idx_cobro_nro_factura');
			$table->index('nro_recibo', 'idx_cobro_nro_recibo');
			$table->index('anio_cobro', 'idx_cobro_anio');
			$table->index(['anio_cobro', 'nro_cobro'], 'idx_cobro_anio_nro');
			$table->index('cod_inscrip', 'idx_cobro_cod_inscrip');

			$table->foreign('id_cuota')->references('id_cuota')->on('cuotas')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_forma_cobro')->references('id_forma_cobro')->on('formas_cobro')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_cuentas_bancarias')->references('id_cuentas_bancarias')->on('cuentas_bancarias')->onDelete('restrict')->onUpdate('restrict');

			if (Schema::hasTable('tipo_cobro')) {
				$table->foreign('cod_tipo_cobro')->references('cod_tipo_cobro')->on('tipo_cobro')->onDelete('restrict')->onUpdate('restrict');
			}
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cobro');
	}
};
