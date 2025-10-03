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
			// Eliminar índice único si existe
			try { DB::statement('DROP INDEX `uk_insc_source_carrera` ON `inscripciones`'); } catch (\Throwable $e) {}
			// Crear índice normal (no único) para acelerar búsquedas
			try { DB::statement('CREATE INDEX `idx_insc_source_carrera` ON `inscripciones` (`source_cod_inscrip`, `carrera`)'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('inscripciones')) {
			try { DB::statement('DROP INDEX `idx_insc_source_carrera` ON `inscripciones`'); } catch (\Throwable $e) {}
			try { DB::statement('CREATE UNIQUE INDEX `uk_insc_source_carrera` ON `inscripciones` (`source_cod_inscrip`, `carrera`)'); } catch (\Throwable $e) {}
		}
	}
};
