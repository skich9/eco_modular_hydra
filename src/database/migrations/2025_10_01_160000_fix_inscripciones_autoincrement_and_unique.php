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
			// 1) Asegurar AUTO_INCREMENT continúe desde MAX(cod_inscrip)+1
			try {
				$max = (int) (DB::table('inscripciones')->max('cod_inscrip') ?? 0);
				$next = $max + 1;
				if ($next > 1) {
					DB::statement('ALTER TABLE `inscripciones` AUTO_INCREMENT = '.$next);
				}
			} catch (\Throwable $e) {
				// Ignorar en motores no-MySQL
			}

			// 2) Índice único para evitar duplicados por origen (si columnas existen)
			if (Schema::hasColumn('inscripciones', 'source_cod_inscrip')) {
				try {
					DB::statement('CREATE UNIQUE INDEX `uk_insc_source_carrera` ON `inscripciones` (`source_cod_inscrip`, `carrera`)');
				} catch (\Throwable $e) {
					// ya existe o DB no soporta
				}
			}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('inscripciones')) {
			try { DB::statement('DROP INDEX `uk_insc_source_carrera` ON `inscripciones`'); } catch (\Throwable $e) {}
		}
	}
};
