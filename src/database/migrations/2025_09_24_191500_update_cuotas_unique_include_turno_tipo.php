<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// Ampliar índice único para permitir coexistencia por tipo también
		try { DB::statement("ALTER TABLE `cuotas` DROP INDEX `uk_cuotas_nombre_gestion_pensum_semestre_turno`"); } catch (\Throwable $e) {}
		try { DB::statement("ALTER TABLE `cuotas` DROP INDEX `uk_cuotas_nombre_gestion_pensum_semestre`"); } catch (\Throwable $e) {}
		try { DB::statement("ALTER TABLE `cuotas` ADD UNIQUE `uk_cuotas_nombre_gestion_pensum_semestre_turno_tipo` (`nombre`, `gestion`, `cod_pensum`, `semestre`, `turno`, `tipo`)"); } catch (\Throwable $e) {}
	}

	public function down(): void
	{
		try { DB::statement("ALTER TABLE `cuotas` DROP INDEX `uk_cuotas_nombre_gestion_pensum_semestre_turno_tipo`"); } catch (\Throwable $e) {}
		try { DB::statement("ALTER TABLE `cuotas` ADD UNIQUE `uk_cuotas_nombre_gestion_pensum_semestre_turno` (`nombre`, `gestion`, `cod_pensum`, `semestre`, `turno`)"); } catch (\Throwable $e) {}
	}
};
