<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) Agregar cod_pensum_sga a inscripciones (para trazar valor origen)
		if (Schema::hasTable('inscripciones')) {
			Schema::table('inscripciones', function (Blueprint $table) {
				if (!Schema::hasColumn('inscripciones', 'cod_pensum_sga')) {
					$table->string('cod_pensum_sga', 50)->nullable()->after('cod_pensum');
				}
			});
			try { DB::statement('CREATE INDEX `idx_insc_cod_pensum_sga` ON `inscripciones` (`cod_pensum_sga`)'); } catch (\Throwable $e) {}
		}

		// 2) Crear tabla de mapeo de pensums entre SGA y local
		if (!Schema::hasTable('pensum_map')) {
			Schema::create('pensum_map', function (Blueprint $table) {
				$table->id();
				$table->string('carrera', 150);
				$table->string('cod_pensum_sga', 50);
				$table->string('cod_pensum_local', 50);
				$table->timestamps();
			});
			try { DB::statement('CREATE UNIQUE INDEX `uk_pensum_map_carrera_sga` ON `pensum_map` (`carrera`, `cod_pensum_sga`)'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `pensum_map` ADD CONSTRAINT `pensum_map_local_fk` FOREIGN KEY (`cod_pensum_local`) REFERENCES `pensums`(`cod_pensum`) ON DELETE RESTRICT'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('inscripciones')) {
			try { DB::statement('DROP INDEX `idx_insc_cod_pensum_sga` ON `inscripciones`'); } catch (\Throwable $e) {}
			Schema::table('inscripciones', function (Blueprint $table) {
				if (Schema::hasColumn('inscripciones', 'cod_pensum_sga')) {
					$table->dropColumn('cod_pensum_sga');
				}
			});
		}

		if (Schema::hasTable('pensum_map')) {
			try { DB::statement('ALTER TABLE `pensum_map` DROP FOREIGN KEY `pensum_map_local_fk`'); } catch (\Throwable $e) {}
			try { DB::statement('DROP INDEX `uk_pensum_map_carrera_sga` ON `pensum_map`'); } catch (\Throwable $e) {}
			Schema::drop('pensum_map');
		}
	}
};
