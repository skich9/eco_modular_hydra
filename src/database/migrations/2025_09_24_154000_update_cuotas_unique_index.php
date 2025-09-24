<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// Ajustar índice único para evitar colisiones por nombre+gestion únicamente
		try { DB::statement("ALTER TABLE `cuotas` DROP INDEX `uk_cuotas_nombre_gestion`"); } catch (\Throwable $e) {}
		try { DB::statement("ALTER TABLE `cuotas` ADD UNIQUE `uk_cuotas_nombre_gestion_pensum_semestre` (`nombre`, `gestion`, `cod_pensum`, `semestre`)"); } catch (\Throwable $e) {}
	}

	public function down(): void
	{
		try { DB::statement("ALTER TABLE `cuotas` DROP INDEX `uk_cuotas_nombre_gestion_pensum_semestre`"); } catch (\Throwable $e) {}
		try { DB::statement("ALTER TABLE `cuotas` ADD UNIQUE `uk_cuotas_nombre_gestion` (`nombre`, `gestion`)"); } catch (\Throwable $e) {}
	}
};
