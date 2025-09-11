<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) Nueva tabla: deudas
		if (!Schema::hasTable('deudas')) {
			Schema::create('deudas', function (Blueprint $table) {
				$table->unsignedBigInteger('cod_ceta');
				$table->unsignedBigInteger('cod_inscrip');
				$table->text('ap_paterno');
				$table->text('ap_materno')->nullable();
				$table->text('nombre');
				$table->text('grupo');
				$table->text('tipo_ins')->nullable();
				$table->decimal('deuda', 10, 2);
				$table->boolean('activo');
				$table->primary(['cod_ceta', 'cod_inscrip']);
			});

			// FKs: deudas.cod_ceta -> estudiantes.cod_ceta ; deudas.cod_inscrip -> inscripciones.cod_inscrip
			Schema::table('deudas', function (Blueprint $table) {
				$table->foreign('cod_ceta', 'fk_deudas_cod_ceta')
					->references('cod_ceta')->on('estudiantes')
					->onDelete('restrict')->onUpdate('restrict');
				$table->foreign('cod_inscrip', 'fk_deudas_cod_inscrip')
					->references('cod_inscrip')->on('inscripciones')
					->onDelete('restrict')->onUpdate('restrict');
			});
		}

		// 2) materia: estado -> activo, descripcion a text, quitar id_parametro_economico
		if (Schema::hasTable('materia')) {
			// Renombrar estado -> activo (evitar DBAL usando SQL directo)
			if (Schema::hasColumn('materia', 'estado') && !Schema::hasColumn('materia', 'activo')) {
				DB::statement("ALTER TABLE `materia` CHANGE `estado` `activo` TINYINT(1) NOT NULL");
			}
			// descripcion a TEXT NULL
			if (Schema::hasColumn('materia', 'descripcion')) {
				DB::statement("ALTER TABLE `materia` MODIFY `descripcion` TEXT NULL");
			}
			// Quitar FK y columna id_parametro_economico si existe
			if (Schema::hasColumn('materia', 'id_parametro_economico')) {
				try { DB::statement('ALTER TABLE `materia` DROP FOREIGN KEY `materia_id_parametro_economico_foreign`'); } catch (\Throwable $e) {}
				Schema::table('materia', function (Blueprint $table) {
					$table->dropColumn('id_parametro_economico');
				});
			}
		}

		// 3) costo_semestral: agregar campos; quitar cod_inscrip
		if (Schema::hasTable('costo_semestral')) {
			Schema::table('costo_semestral', function (Blueprint $table) {
				if (!Schema::hasColumn('costo_semestral', 'tipo_costo')) {
					$table->string('tipo_costo', 50)->nullable()->after('monto_semestre');
				}
				if (!Schema::hasColumn('costo_semestral', 'costo_fijo')) {
					$table->boolean('costo_fijo')->default(false)->after('tipo_costo');
				}
				if (!Schema::hasColumn('costo_semestral', 'turno')) {
					$table->string('turno', 20)->nullable()->after('semestre');
				}
				if (!Schema::hasColumn('costo_semestral', 'valor_credito')) {
					$table->decimal('valor_credito', 10, 2)->default(0)->after('id_usuario');
				}
			});
			if (Schema::hasColumn('costo_semestral', 'cod_inscrip')) {
				try { DB::statement('ALTER TABLE `costo_semestral` DROP FOREIGN KEY `costo_semestral_cod_inscrip_foreign`'); } catch (\Throwable $e) {}
				Schema::table('costo_semestral', function (Blueprint $table) {
					$table->dropColumn('cod_inscrip');
				});
			}
			// Unicidad lógica por pensum+gestion+semestre
			try { DB::statement('CREATE UNIQUE INDEX `uk_costo_semestral_pensum_gestion_sem` ON `costo_semestral` (`cod_pensum`, `gestion`, `semestre`)'); } catch (\Throwable $e) {}
		}

		// 4) costo_materia: agregar cod_pensum, quitar nombre_materia, ajustar PK, FK compuesta a materia
		if (Schema::hasTable('costo_materia')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				if (!Schema::hasColumn('costo_materia', 'cod_pensum')) {
					$table->string('cod_pensum', 50)->after('id_costo_materia');
				}
				if (Schema::hasColumn('costo_materia', 'nombre_materia')) {
					$table->dropColumn('nombre_materia');
				}
			});
			// Ajustar PK: (id_costo_materia, cod_pensum, sigla_materia, gestion)
			try { DB::statement('ALTER TABLE `costo_materia` DROP PRIMARY KEY'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `costo_materia` ADD PRIMARY KEY (`id_costo_materia`, `cod_pensum`, `sigla_materia`, `gestion`)'); } catch (\Throwable $e) {}
			// FK compuesta a materia(sigla_materia, cod_pensum)
			try { DB::statement('ALTER TABLE `costo_materia` DROP FOREIGN KEY `costo_materia_sigla_materia_foreign`'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `costo_materia` ADD CONSTRAINT `fk_costo_materia_materia` FOREIGN KEY (`sigla_materia`, `cod_pensum`) REFERENCES `materia`(`sigla_materia`, `cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT'); } catch (\Throwable $e) {}
		}

		// 5) cuotas: agregar columnas y ajustar tipos; PK compuesta (id_cuota, gestion)
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
				if (Schema::hasColumn('cuotas', 'estado') && !Schema::hasColumn('cuotas', 'activo')) {
					DB::statement("ALTER TABLE `cuotas` CHANGE `estado` `activo` TINYINT(1) NULL");
				}
				if (Schema::hasColumn('cuotas', 'tipo')) {
					DB::statement("ALTER TABLE `cuotas` MODIFY `tipo` VARCHAR(150) NULL");
				}
			});
			// Ajustar PK compuesta (id_cuota, gestion)
			try { DB::statement('ALTER TABLE `cuotas` DROP PRIMARY KEY'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `cuotas` ADD PRIMARY KEY (`id_cuota`, `gestion`)'); } catch (\Throwable $e) {}

			// FK compuesta desde cuotas (cod_pensum, gestion, semestre) -> costo_semestral (cod_pensum, gestion, semestre)
			try { DB::statement('ALTER TABLE `cuotas` DROP FOREIGN KEY `fk_cuotas_costo_semestral`'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `cuotas` ADD CONSTRAINT `fk_cuotas_costo_semestral` FOREIGN KEY (`cod_pensum`, `gestion`, `semestre`) REFERENCES `costo_semestral`(`cod_pensum`, `gestion`, `semestre`) ON UPDATE RESTRICT ON DELETE RESTRICT'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		// Reversión mínima segura
		Schema::dropIfExists('deudas');
		// No revertimos cambios destructivos en tablas existentes para evitar pérdida de datos.
	}
};
