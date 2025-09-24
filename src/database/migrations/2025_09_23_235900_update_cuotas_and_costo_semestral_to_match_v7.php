<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) parametros_cuota: renombrar fecha_venecimiento -> fecha_vencimiento
		if (Schema::hasTable('parametros_cuota')) {
			$hasTypo = Schema::hasColumn('parametros_cuota', 'fecha_venecimiento');
			$hasCorrect = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento');
			if ($hasTypo && !$hasCorrect) {
				try {
					DB::statement("ALTER TABLE `parametros_cuota` CHANGE `fecha_venecimiento` `fecha_vencimiento` DATE NOT NULL");
				} catch (\Throwable $e) {
					// ignore if already renamed or DB doesn't support CHANGE
				}
			}
		}

		// 2) costo_semestral: asegurar tipos y longitudes según v7 (turno VARCHAR(150) NULL)
		if (Schema::hasTable('costo_semestral')) {
			// Asegurar que la columna 'turno' exista y sea VARCHAR(150) NULL
			if (Schema::hasColumn('costo_semestral', 'turno')) {
				try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `turno` VARCHAR(150) NULL"); } catch (\Throwable $e) {}
			} else {
				Schema::table('costo_semestral', function (Blueprint $table) {
					$table->string('turno', 150)->nullable()->after('semestre');
				});
			}
			// Mantener costo_fijo y valor_credito NOT NULL (no-op si ya lo están)
			try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `costo_fijo` TINYINT(1) NOT NULL"); } catch (\Throwable $e) {}
			try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `valor_credito` DECIMAL(10,2) NOT NULL"); } catch (\Throwable $e) {}
		}

		// 3) cuotas: asegurar estructura clave según v7
		if (Schema::hasTable('cuotas')) {
			Schema::table('cuotas', function (Blueprint $table) {
				if (!Schema::hasColumn('cuotas', 'gestion')) {
					$table->string('gestion', 30)->nullable()->after('monto');
				}
				if (!Schema::hasColumn('cuotas', 'cod_pensum')) {
					$table->string('cod_pensum', 50)->nullable()->after('gestion');
				}
				if (!Schema::hasColumn('cuotas', 'semestre')) {
					$table->string('semestre', 30)->nullable()->after('descripcion');
				}
				if (!Schema::hasColumn('cuotas', 'turno')) {
					$table->string('turno', 150)->nullable()->after('fecha_vencimiento');
				}
			});
			// Asegurar tipos/longitudes de columnas existentes
			try { DB::statement("ALTER TABLE `cuotas` MODIFY `turno` VARCHAR(150) NULL"); } catch (\Throwable $e) {}
			try { DB::statement("ALTER TABLE `cuotas` MODIFY `tipo` VARCHAR(150) NULL"); } catch (\Throwable $e) {}

			// Ajustar PK compuesta (id_cuota, gestion)
			try { DB::statement('ALTER TABLE `cuotas` DROP PRIMARY KEY'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `cuotas` ADD PRIMARY KEY (`id_cuota`, `gestion`)'); } catch (\Throwable $e) {}

			// FK compuesta hacia costo_semestral (cod_pensum, gestion, semestre)
			try { DB::statement('ALTER TABLE `cuotas` DROP FOREIGN KEY `fk_cuotas_costo_semestral`'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `cuotas` ADD CONSTRAINT `fk_cuotas_costo_semestral` FOREIGN KEY (`cod_pensum`, `gestion`, `semestre`) REFERENCES `costo_semestral`(`cod_pensum`, `gestion`, `semestre`) ON UPDATE RESTRICT ON DELETE RESTRICT'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		// Reversión mínima y segura.
		// 1) parametros_cuota: intentar revertir nombre si existe la columna correcta
		if (Schema::hasTable('parametros_cuota')) {
			$hasCorrect = Schema::hasColumn('parametros_cuota', 'fecha_vencimiento');
			$hasTypo = Schema::hasColumn('parametros_cuota', 'fecha_venecimiento');
			if ($hasCorrect && !$hasTypo) {
				try {
					DB::statement("ALTER TABLE `parametros_cuota` CHANGE `fecha_vencimiento` `fecha_venecimiento` DATE NOT NULL");
				} catch (\Throwable $e) {}
			}
		}
		// 2) costo_semestral: no revertimos NOT NULL -> NULL a su estado previo para evitar pérdida
		// 3) cuotas: no revertimos PKs ni FKs para evitar inconsistencias
	}
};
