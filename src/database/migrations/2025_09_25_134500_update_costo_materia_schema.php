<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('costo_materia')) {
			return;
		}

		// Asegurar columnas según especificación (modulo eco resumido (9).sql)
		Schema::table('costo_materia', function (Blueprint $table) {
			// Agregar cod_pensum si faltara (resguardado)
			if (!Schema::hasColumn('costo_materia', 'cod_pensum')) {
				$table->string('cod_pensum', 50)->after('id_costo_materia');
			}
			// Agregar valor_credito (NOT NULL). Inicialmente con default 0 para no romper datos existentes
			if (!Schema::hasColumn('costo_materia', 'valor_credito')) {
				$table->decimal('valor_credito', 10, 2)->default(0)->after('gestion');
			}
		});

		// Si existe columna obsoleta, eliminarla
		if (Schema::hasColumn('costo_materia', 'nombre_materia')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				$table->dropColumn('nombre_materia');
			});
		}

		// La definición final no contempla nro_creditos en costo_materia; retirarlo si existe
		if (Schema::hasColumn('costo_materia', 'nro_creditos')) {
			Schema::table('costo_materia', function (Blueprint $table) {
				$table->dropColumn('nro_creditos');
			});
		}

		// Asegurar PK compuesta: (id_costo_materia, cod_pensum, sigla_materia, gestion)
		try { DB::statement('ALTER TABLE `costo_materia` DROP PRIMARY KEY'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE `costo_materia` ADD PRIMARY KEY (`id_costo_materia`, `cod_pensum`, `sigla_materia`, `gestion`)'); } catch (\Throwable $e) {}

		// Asegurar FK compuesta a materia(sigla_materia, cod_pensum)
		try { DB::statement('ALTER TABLE `costo_materia` DROP FOREIGN KEY `fk_costo_materia_materia`'); } catch (\Throwable $e) {}
		try { DB::statement('ALTER TABLE `costo_materia` ADD CONSTRAINT `fk_costo_materia_materia` FOREIGN KEY (`sigla_materia`, `cod_pensum`) REFERENCES `materia`(`sigla_materia`, `cod_pensum`) ON DELETE RESTRICT ON UPDATE RESTRICT'); } catch (\Throwable $e) {}
	}

	public function down(): void
	{
		if (!Schema::hasTable('costo_materia')) { return; }
		// Reversión mínima segura: eliminar columna valor_credito si existe y (opcional) re-crear nro_creditos como nullable
		Schema::table('costo_materia', function (Blueprint $table) {
			if (Schema::hasColumn('costo_materia', 'valor_credito')) {
				$table->dropColumn('valor_credito');
			}
			if (!Schema::hasColumn('costo_materia', 'nro_creditos')) {
				$table->decimal('nro_creditos', 10, 2)->nullable()->after('gestion');
			}
		});
		// No revertimos PK/FK para evitar inconsistencias con otras partes del sistema
	}
};
