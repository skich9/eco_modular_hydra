<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('inscripciones')) {
			Schema::table('inscripciones', function (Blueprint $table) {
				if (!Schema::hasColumn('inscripciones', 'carrera')) {
					$table->string('carrera', 150)->nullable()->after('cod_inscrip');
				}
				if (!Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
					$table->unsignedBigInteger('source_cod_inscrip')->nullable()->after('carrera');
				}
				// Índices de apoyo si no existen
				if (!Schema::hasColumn('inscripciones', 'cod_curso')) {
					// ya agregado antes, pero por si acaso
					$table->text('cod_curso')->nullable()->after('cod_pensum');
				}
			});

			// Índice único idempotente
			try {
				DB::statement('CREATE UNIQUE INDEX `uk_insc_carrera_sourceid` ON `inscripciones` (`carrera`, `source_cod_inscrip`)');
			} catch (\Throwable $e) {}

			// Índices de apoyo
			try { DB::statement('CREATE INDEX `idx_insc_cod_ceta` ON `inscripciones` (`cod_ceta`)'); } catch (\Throwable $e) {}
			try { DB::statement('CREATE INDEX `idx_insc_cod_pensum` ON `inscripciones` (`cod_pensum`)'); } catch (\Throwable $e) {}
			try { DB::statement('CREATE INDEX `idx_insc_gestion` ON `inscripciones` (`gestion`)'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('inscripciones')) {
			// Eliminar índices si existen
			try { DB::statement('DROP INDEX `uk_insc_carrera_sourceid` ON `inscripciones`'); } catch (\Throwable $e) {}
			try { DB::statement('DROP INDEX `idx_insc_cod_ceta` ON `inscripciones`'); } catch (\Throwable $e) {}
			try { DB::statement('DROP INDEX `idx_insc_cod_pensum` ON `inscripciones`'); } catch (\Throwable $e) {}
			try { DB::statement('DROP INDEX `idx_insc_gestion` ON `inscripciones`'); } catch (\Throwable $e) {}

			Schema::table('inscripciones', function (Blueprint $table) {
				if (Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
					$table->dropColumn('source_cod_inscrip');
				}
				if (Schema::hasColumn('inscripciones', 'carrera')) {
					$table->dropColumn('carrera');
				}
			});
		}
	}
};
