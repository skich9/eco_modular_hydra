<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				if (!Schema::hasColumn('qr_transacciones', 'imagenQr')) {
					$table->binary('imagenQr')->nullable()->after('codigo_qr');
				}
				if (!Schema::hasColumn('qr_transacciones', 'nro_transaccion')) {
					$table->integer('nro_transaccion')->nullable()->after('imagenQr');
				}
				if (!Schema::hasColumn('qr_transacciones', 'gestion')) {
					$table->string('gestion', 255)->nullable()->after('nro_transaccion');
				}
				if (!Schema::hasColumn('qr_transacciones', 'numeroordenoriginante')) {
					$table->integer('numeroordenoriginante')->nullable()->after('gestion');
				}
				if (!Schema::hasColumn('qr_transacciones', 'cuenta_cliente')) {
					$table->string('cuenta_cliente', 50)->nullable()->after('numeroordenoriginante');
				}
				if (!Schema::hasColumn('qr_transacciones', 'nombre_cliente')) {
					$table->string('nombre_cliente', 255)->nullable()->after('cuenta_cliente');
				}
				if (!Schema::hasColumn('qr_transacciones', 'documento_cliente')) {
					$table->integer('documento_cliente')->nullable()->after('nombre_cliente');
				}
			});
		}

		if (Schema::hasTable('qr_conceptos_detalle')) {
			Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
				if (!Schema::hasColumn('qr_conceptos_detalle', 'nro_cuota')) {
					$table->tinyInteger('nro_cuota')->nullable()->after('observaciones');
				}
				if (!Schema::hasColumn('qr_conceptos_detalle', 'descuento')) {
					$table->decimal('descuento', 10, 2)->default(0)->after('precio_unitario');
				}
				if (!Schema::hasColumn('qr_conceptos_detalle', 'multa')) {
					$table->decimal('multa', 10, 2)->default(0)->after('descuento');
				}
				if (!Schema::hasColumn('qr_conceptos_detalle', 'turno')) {
					$table->string('turno', 50)->nullable()->after('multa');
				}
				if (!Schema::hasColumn('qr_conceptos_detalle', 'monto_saldo')) {
					$table->decimal('monto_saldo', 10, 2)->nullable()->after('turno');
				}
			});
		}

		if (Schema::hasTable('qr_respuestas_banco')) {
			Schema::table('qr_respuestas_banco', function (Blueprint $table) {
				if (!Schema::hasColumn('qr_respuestas_banco', 'alias')) {
					$table->string('alias', 100)->nullable()->after('mensaje_respuesta');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'numeroordenoriginante')) {
					$table->string('numeroordenoriginante', 50)->nullable()->after('alias');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'monto')) {
					$table->decimal('monto', 10, 2)->nullable()->after('numeroordenoriginante');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'id_qr')) {
					$table->string('id_qr', 30)->nullable()->after('monto');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'moneda')) {
					$table->string('moneda', 10)->nullable()->after('id_qr');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'fecha_proceso')) {
					$table->string('fecha_proceso', 100)->nullable()->after('moneda');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'cuentaCliente')) {
					$table->string('cuentaCliente', 50)->nullable()->after('fecha_proceso');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'nombreCliente')) {
					$table->string('nombreCliente', 150)->nullable()->after('cuentaCliente');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'documentoCliente')) {
					$table->string('documentoCliente', 50)->nullable()->after('nombreCliente');
				}
				if (!Schema::hasColumn('qr_respuestas_banco', 'observaciones')) {
					$table->string('observaciones', 255)->nullable()->after('documentoCliente');
				}
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				foreach (['imagenQr','nro_transaccion','gestion','numeroordenoriginante','cuenta_cliente','nombre_cliente','documento_cliente'] as $col) {
					try { $table->dropColumn($col); } catch (\Throwable $e) {}
				}
			});
		}
		if (Schema::hasTable('qr_conceptos_detalle')) {
			Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
				foreach (['nro_cuota','descuento','multa','turno','monto_saldo'] as $col) {
					try { $table->dropColumn($col); } catch (\Throwable $e) {}
				}
			});
		}
		if (Schema::hasTable('qr_respuestas_banco')) {
			Schema::table('qr_respuestas_banco', function (Blueprint $table) {
				foreach (['alias','numeroordenoriginante','monto','id_qr','moneda','fecha_proceso','cuentaCliente','nombreCliente','documentoCliente','observaciones'] as $col) {
					try { $table->dropColumn($col); } catch (\Throwable $e) {}
				}
			});
		}
	}
};
