<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		// NOTA: Esta migración ya no es necesaria.
		// La tabla costo_semestral ahora se crea con un índice NORMAL (no único) desde la migración inicial.
		// El índice normal permite múltiples registros con la misma combinación de (cod_pensum, gestion, semestre, tipo_costo, turno)
		// lo cual es necesario para el funcionamiento correcto del sistema.

		// if (Schema::hasTable('costo_semestral')) {
		// 	// Eliminar índice único anterior (cod_pensum, gestion, semestre)
		// 	try { DB::statement('ALTER TABLE `costo_semestral` DROP INDEX `uk_costo_semestral_pensum_gestion_sem`'); } catch (\Throwable $e) {}
		// 	// Crear el nuevo índice único compuesto
		// 	try { DB::statement('ALTER TABLE `costo_semestral` ADD UNIQUE `uk_costo_semestral_pensum_gestion_sem_tipo_turno` (`cod_pensum`, `gestion`, `semestre`, `tipo_costo`, `turno`)'); } catch (\Throwable $e) {}
		// }

		// La FK desde cuotas(cod_pensum, gestion, semestre) -> costo_semestral(cod_pensum, gestion, semestre)
		// deja de ser válida porque ya no hay unicidad por esas 3 columnas.
		if (Schema::hasTable('cuotas')) {
			try { DB::statement('ALTER TABLE `cuotas` DROP FOREIGN KEY `fk_cuotas_costo_semestral`'); } catch (\Throwable $e) {}
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('costo_semestral')) {
			// No recrear índices únicos en costo_semestral.
			// La tabla debe permanecer con índice normal desde la migración inicial.
			try { DB::statement('ALTER TABLE `costo_semestral` DROP INDEX `uk_costo_semestral_pensum_gestion_sem_tipo_turno`'); } catch (\Throwable $e) {}
		}

		// No restauramos la FK en cuotas en el down para evitar fallos si existen múltiples costos por semestre.
	}
};
