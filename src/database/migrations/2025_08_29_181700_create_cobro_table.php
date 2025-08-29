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
			$table->unsignedBigInteger('id_cuota')->nullable();
			$table->string('gestion', 255)->nullable();
			$table->integer('nro_cobro');
			$table->decimal('monto', 10, 2);
			$table->date('fecha_cobro');
			$table->boolean('cobro_completo')->nullable();
			$table->text('observaciones')->nullable();
			$table->unsignedBigInteger('id_usuario');
			$table->string('id_forma_cobro', 255);
			$table->decimal('pu_mensualidad', 10, 2);
			$table->tinyInteger('order');
			$table->string('descuento', 255)->nullable();
			$table->unsignedInteger('id_cuentas_bancarias')->nullable();
			$table->integer('nro_factura')->nullable();
			$table->integer('nro_recibo')->nullable();
			$table->integer('id_item')->nullable();
			$table->integer('id_asignacion_costo')->nullable();
			$table->timestamps();

			$table->primary(['cod_ceta', 'cod_pensum', 'tipo_inscripcion', 'nro_cobro']);
			// Index requerido para claves foráneas que referencian solo nro_cobro
			$table->index('nro_cobro');

			$table->foreign('id_cuota')->references('id_cuota')->on('cuotas')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_usuario')->references('id_usuario')->on('usuarios')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_forma_cobro')->references('id_forma_cobro')->on('formas_cobro')->onDelete('restrict')->onUpdate('restrict');
			$table->foreign('id_cuentas_bancarias')->references('id_cuentas_bancarias')->on('cuentas_bancarias')->onDelete('restrict')->onUpdate('restrict');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('cobro');
	}
};
