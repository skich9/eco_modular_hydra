<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) estudiantes: quitar campos irrelevantes y ajustar longitudes
		if (Schema::hasTable('estudiantes')) {
			// Quitar FK y columna cod_pensum
			if (Schema::hasColumn('estudiantes', 'cod_pensum')) {
				try { DB::statement('ALTER TABLE `estudiantes` DROP FOREIGN KEY `estudiantes_cod_pensum_foreign`'); } catch (\Throwable $e) {}
				Schema::table('estudiantes', function (Blueprint $table) {
					$table->dropColumn('cod_pensum');
				});
			}
			// Ajustar tipos/longitudes según nuevo esquema
			DB::statement("ALTER TABLE `estudiantes` MODIFY `ci` VARCHAR(50) NOT NULL");
			DB::statement("ALTER TABLE `estudiantes` MODIFY `nombres` VARCHAR(150) NOT NULL");
			DB::statement("ALTER TABLE `estudiantes` MODIFY `ap_paterno` VARCHAR(150) NOT NULL");
			DB::statement("ALTER TABLE `estudiantes` MODIFY `ap_materno` VARCHAR(150) NOT NULL");
			DB::statement("ALTER TABLE `estudiantes` MODIFY `email` VARCHAR(100) NULL");
			DB::statement("ALTER TABLE `estudiantes` MODIFY `estado` VARCHAR(50) NULL");
		}

		// 2) inscripciones: quitar campos y ajustar tipos
		if (Schema::hasTable('inscripciones')) {
			Schema::table('inscripciones', function (Blueprint $table) {
				// Agregar cod_curso (según nuevo esquema), inicialmente nullable para no fallar si hay datos
				if (!Schema::hasColumn('inscripciones', 'cod_curso')) {
					$table->text('cod_curso')->nullable()->after('cod_pensum');
				}
				if (Schema::hasColumn('inscripciones', 'nro_materia')) {
					$table->dropColumn('nro_materia');
				}
				if (Schema::hasColumn('inscripciones', 'nro_materia_aprob')) {
					$table->dropColumn('nro_materia_aprob');
				}
				if (Schema::hasColumn('inscripciones', 'deleted_at')) {
					$table->dropColumn('deleted_at');
				}
			});
			// Ajustar tipos longitud (SQL directo para ser idempotente en MySQL)
			DB::statement("ALTER TABLE `inscripciones` MODIFY `gestion` VARCHAR(20) NOT NULL");
			DB::statement("ALTER TABLE `inscripciones` MODIFY `tipo_estudiante` VARCHAR(20) NULL");
			DB::statement("ALTER TABLE `inscripciones` MODIFY `tipo_inscripcion` VARCHAR(30) NOT NULL");
		}

		// 3) costo_semestral: turno 150 NOT NULL, asegurar NOT NULL en costo_fijo y valor_credito
		if (Schema::hasTable('costo_semestral')) {
			// Prellenar valores nulos de turno antes de NOT NULL
			try { DB::statement("UPDATE `costo_semestral` SET `turno` = 'REGULAR' WHERE `turno` IS NULL OR `turno` = ''"); } catch (\Throwable $e) {}
			try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `turno` VARCHAR(150) NOT NULL"); } catch (\Throwable $e) {}
			try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `costo_fijo` TINYINT(1) NOT NULL"); } catch (\Throwable $e) {}
			try { DB::statement("ALTER TABLE `costo_semestral` MODIFY `valor_credito` DECIMAL(10,2) NOT NULL"); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		// Reversión mínima segura
		// No eliminamos datos ni revertimos reducciones de tipo para evitar pérdida
	}
};
