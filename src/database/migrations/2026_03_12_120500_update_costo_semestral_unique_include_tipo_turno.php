<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (Schema::hasTable('costo_semestral')) {
			// Eliminar índice único anterior (cod_pensum, gestion, semestre)
			try { DB::statement('ALTER TABLE `costo_semestral` DROP INDEX `uk_costo_semestral_pensum_gestion_sem`'); } catch (\Throwable $e) {}
			// Crear el nuevo índice único compuesto
			try { DB::statement('ALTER TABLE `costo_semestral` ADD UNIQUE `uk_costo_semestral_pensum_gestion_sem_tipo_turno` (`cod_pensum`, `gestion`, `semestre`, `tipo_costo`, `turno`)'); } catch (\Throwable $e) {}
		}

		// La FK desde cuotas(cod_pensum, gestion, semestre) -> costo_semestral(cod_pensum, gestion, semestre)
		// deja de ser válida porque ya no hay unicidad por esas 3 columnas.
		if (Schema::hasTable('cuotas')) {
			try { DB::statement('ALTER TABLE `cuotas` DROP FOREIGN KEY `fk_cuotas_costo_semestral`'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('costo_semestral')) {
			try { DB::statement('ALTER TABLE `costo_semestral` DROP INDEX `uk_costo_semestral_pensum_gestion_sem_tipo_turno`'); } catch (\Throwable $e) {}
			try { DB::statement('ALTER TABLE `costo_semestral` ADD UNIQUE `uk_costo_semestral_pensum_gestion_sem` (`cod_pensum`, `gestion`, `semestre`)'); } catch (\Throwable $e) {}
		}

		// No restauramos la FK en cuotas en el down para evitar fallos si existen múltiples costos por semestre.
	}
};
