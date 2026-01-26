<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	/**
	 * Alinea el esquema de migraciones con la estructura real de la base de datos
	 * según angular_laravel_project.sql (19/01/2026)
	 */
	public function up(): void
	{
		// 1. MATERIA: Ajustar campos
		if (Schema::hasTable('materia')) {
			Schema::table('materia', function (Blueprint $table) {
				// Renombrar 'estado' a 'activo' si existe
				if (Schema::hasColumn('materia', 'estado') && !Schema::hasColumn('materia', 'activo')) {
					$table->renameColumn('estado', 'activo');
				}

				// Cambiar tipo de 'descripcion' de varchar(50) a text
				if (Schema::hasColumn('materia', 'descripcion')) {
					$table->text('descripcion')->nullable()->change();
				}

				// Eliminar FK a parametros_economicos si existe
				try {
					$table->dropForeign(['id_parametro_economico']);
				} catch (\Throwable $e) {}

				// Eliminar columna id_parametro_economico si existe
				if (Schema::hasColumn('materia', 'id_parametro_economico')) {
					$table->dropColumn('id_parametro_economico');
				}
			});
		}

		// 2. COSTO_MATERIA: Ya tiene cod_pensum y valor_credito según migración 2025_09_25_134500
		// Verificar que estén presentes
		if (Schema::hasTable('costo_materia')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				if (!Schema::hasColumn('costo_materia', 'cod_pensum')) {
					$table->string('cod_pensum', 50)->after('id_costo_materia');
				}
				if (!Schema::hasColumn('costo_materia', 'valor_credito')) {
					$table->decimal('valor_credito', 10, 2)->default(0.00)->after('gestion');
				}
			});
		}

		// 3. COBRO: Ya tiene todos los campos según create_cobro_table
		// Solo verificar que fecha_cobro sea datetime (ya está en la migración original)

		// 4. COBROS_DETALLE_REGULAR: Agregar FK compuesta si no existe
		if (Schema::hasTable('cobros_detalle_regular')) {
			try {
				DB::statement('ALTER TABLE `cobros_detalle_regular` DROP FOREIGN KEY IF EXISTS `fk_cdr_cobro`');
			} catch (\Throwable $e) {}

			try {
				DB::statement('
					ALTER TABLE `cobros_detalle_regular`
					ADD CONSTRAINT `fk_cdr_cobro`
					FOREIGN KEY (`cod_ceta`, `cod_pensum`, `tipo_inscripcion`, `nro_cobro`)
					REFERENCES `cobro` (`cod_ceta`, `cod_pensum`, `tipo_inscripcion`, `nro_cobro`)
					ON DELETE CASCADE ON UPDATE CASCADE
				');
			} catch (\Throwable $e) {}
		}

		// 5. COBROS_DETALLE_MULTA: Agregar FK compuesta si no existe
		if (Schema::hasTable('cobros_detalle_multa')) {
			try {
				DB::statement('ALTER TABLE `cobros_detalle_multa` DROP FOREIGN KEY IF EXISTS `fk_cdm_cobro`');
			} catch (\Throwable $e) {}

			try {
				DB::statement('
					ALTER TABLE `cobros_detalle_multa`
					ADD CONSTRAINT `fk_cdm_cobro`
					FOREIGN KEY (`cod_ceta`, `cod_pensum`, `tipo_inscripcion`, `nro_cobro`)
					REFERENCES `cobro` (`cod_ceta`, `cod_pensum`, `tipo_inscripcion`, `nro_cobro`)
					ON DELETE CASCADE ON UPDATE CASCADE
				');
			} catch (\Throwable $e) {}
		}

		// 6. RECIBO: Ya tiene cliente y nro_documento_cobro según migración 2025_12_30_150100

		// 7. FACTURA: Agregar campos faltantes
		if (Schema::hasTable('factura')) {
			Schema::table('factura', function (Blueprint $table) {
				// mensaje_sin (text)
				if (!Schema::hasColumn('factura', 'mensaje_sin')) {
					$table->text('mensaje_sin')->nullable()->after('eliminacion_factura');
				}
			});
		}

		// 8. FACTURA_DETALLE: Ya tiene codigo_interno según migración 2025_11_27_154900

		// 9. QR_TRANSACCIONES: Agregar campos faltantes
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				// imagenQr (blob)
				if (!Schema::hasColumn('qr_transacciones', 'imagenQr')) {
					$table->binary('imagenQr')->nullable()->after('codigo_qr');
				}

				// nro_transaccion (bigint unsigned)
				if (!Schema::hasColumn('qr_transacciones', 'nro_transaccion')) {
					$table->unsignedBigInteger('nro_transaccion')->nullable()->after('imagenQr');
				}

				// gestion (varchar 255)
				if (!Schema::hasColumn('qr_transacciones', 'gestion')) {
					$table->string('gestion', 255)->nullable()->after('nro_transaccion');
				}

				// numeroordenoriginante (bigint unsigned)
				if (!Schema::hasColumn('qr_transacciones', 'numeroordenoriginante')) {
					$table->unsignedBigInteger('numeroordenoriginante')->nullable()->after('gestion');
				}

				// cuenta_cliente (varchar 50)
				if (!Schema::hasColumn('qr_transacciones', 'cuenta_cliente')) {
					$table->string('cuenta_cliente', 50)->nullable()->after('numeroordenoriginante');
				}

				// nombre_cliente (varchar 255)
				if (!Schema::hasColumn('qr_transacciones', 'nombre_cliente')) {
					$table->string('nombre_cliente', 255)->nullable()->after('cuenta_cliente');
				}

				// documento_cliente (bigint unsigned) - ya existe tipo_identidad_cliente
				if (!Schema::hasColumn('qr_transacciones', 'documento_cliente')) {
					$table->unsignedBigInteger('documento_cliente')->nullable()->after('tipo_identidad_cliente');
				}

				// Los campos processed, processed_at, saved_by_user, process_error, batch_procesado_at
				// ya existen según migraciones 2025_11_10_182500 y 2025_10_29_160100
			});
		}

		// 10. QR_CONCEPTOS_DETALLE: Agregar campos faltantes
		if (Schema::hasTable('qr_conceptos_detalle')) {
			Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
				// nro_cuota (tinyint)
				if (!Schema::hasColumn('qr_conceptos_detalle', 'nro_cuota')) {
					$table->tinyInteger('nro_cuota')->nullable()->after('observaciones');
				}

				// descuento (decimal 10,2) - ya existe según migración 2026_01_16_160000

				// multa (decimal 10,2) - ya existe según migración 2026_01_16_160000

				// turno (varchar 50)
				if (!Schema::hasColumn('qr_conceptos_detalle', 'turno')) {
					$table->string('turno', 50)->nullable()->after('multa');
				}

				// monto_saldo (decimal 10,2)
				if (!Schema::hasColumn('qr_conceptos_detalle', 'monto_saldo')) {
					$table->decimal('monto_saldo', 10, 2)->nullable()->after('turno');
				}
			});
		}

		// 11. REZAGADOS: Crear tabla si no existe o agregar campos faltantes
		if (!Schema::hasTable('rezagados')) {
			Schema::create('rezagados', function (Blueprint $table) {
				$table->unsignedBigInteger('cod_inscrip');
				$table->integer('num_rezagado');
				$table->integer('num_pago_rezagado');
				$table->integer('num_factura')->nullable();
				$table->integer('num_recibo')->nullable();
				$table->timestamp('fecha_pago');
				$table->decimal('monto', 10, 2);
				$table->boolean('pago_completo');
				$table->string('observaciones', 150)->nullable();
				$table->unsignedBigInteger('usuario');
				$table->string('materia', 255)->nullable();
				$table->char('parcial', 1)->nullable();
				$table->timestamps();

				$table->primary(['cod_inscrip', 'num_rezagado', 'num_pago_rezagado']);
			});
		} else {
			Schema::table('rezagados', function (Blueprint $table) {
				// Verificar y agregar campos si faltan
				if (!Schema::hasColumn('rezagados', 'num_rezagado')) {
					$table->integer('num_rezagado')->after('cod_inscrip');
				}
				if (!Schema::hasColumn('rezagados', 'num_factura')) {
					$table->integer('num_factura')->nullable()->after('num_pago_rezagado');
				}
				if (!Schema::hasColumn('rezagados', 'num_recibo')) {
					$table->integer('num_recibo')->nullable()->after('num_factura');
				}
				if (!Schema::hasColumn('rezagados', 'fecha_pago')) {
					$table->timestamp('fecha_pago')->after('num_recibo');
				}
				if (!Schema::hasColumn('rezagados', 'monto')) {
					$table->decimal('monto', 10, 2)->after('fecha_pago');
				}
				if (!Schema::hasColumn('rezagados', 'pago_completo')) {
					$table->boolean('pago_completo')->after('monto');
				}
			});
		}
	}

	public function down(): void
	{
		// Reversión de cambios

		// MATERIA
		if (Schema::hasTable('materia')) {
			Schema::table('materia', function (Blueprint $table) {
				if (Schema::hasColumn('materia', 'activo') && !Schema::hasColumn('materia', 'estado')) {
					$table->renameColumn('activo', 'estado');
				}
			});
		}

		// COBROS_DETALLE_REGULAR
		if (Schema::hasTable('cobros_detalle_regular')) {
			try {
				DB::statement('ALTER TABLE `cobros_detalle_regular` DROP FOREIGN KEY `fk_cdr_cobro`');
			} catch (\Throwable $e) {}
		}

		// COBROS_DETALLE_MULTA
		if (Schema::hasTable('cobros_detalle_multa')) {
			try {
				DB::statement('ALTER TABLE `cobros_detalle_multa` DROP FOREIGN KEY `fk_cdm_cobro`');
			} catch (\Throwable $e) {}
		}

		// FACTURA
		if (Schema::hasTable('factura')) {
			Schema::table('factura', function (Blueprint $table) {
				if (Schema::hasColumn('factura', 'mensaje_sin')) {
					$table->dropColumn('mensaje_sin');
				}
			});
		}

		// QR_TRANSACCIONES
		if (Schema::hasTable('qr_transacciones')) {
			Schema::table('qr_transacciones', function (Blueprint $table) {
				$columns = ['imagenQr', 'nro_transaccion', 'gestion', 'numeroordenoriginante',
							'cuenta_cliente', 'nombre_cliente', 'documento_cliente'];
				foreach ($columns as $col) {
					if (Schema::hasColumn('qr_transacciones', $col)) {
						$table->dropColumn($col);
					}
				}
			});
		}

		// QR_CONCEPTOS_DETALLE
		if (Schema::hasTable('qr_conceptos_detalle')) {
			Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
				$columns = ['nro_cuota', 'turno', 'monto_saldo'];
				foreach ($columns as $col) {
					if (Schema::hasColumn('qr_conceptos_detalle', $col)) {
						$table->dropColumn($col);
					}
				}
			});
		}

		// REZAGADOS - no eliminar la tabla en down para evitar pérdida de datos
	}
};
