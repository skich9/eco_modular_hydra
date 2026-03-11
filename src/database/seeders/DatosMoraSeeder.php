<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatosMoraSeeder extends Seeder
{
	/**
	 * Run the database seeds.
	 *
	 * Crea la configuración inicial de moras según las reglas de negocio:
	 * - Semestres 1-2: Día de corte 15 de cada mes
	 * - Semestres 3-4: Día de corte 16 de cada mes
	 * - Semestres 5-6: Día de corte 17 de cada mes
	 * - Si cae en fin de semana, se mueve al siguiente día hábil
	 */
	public function run(): void
	{
		$this->command->info('🔧 Iniciando configuración de moras...');

		// Obtener gestión actual
		$gestionActual = Carbon::now()->year;

		// 1. Crear configuración general de mora para la gestión actual
		$this->command->info("\n📋 Creando configuración general de mora para gestión {$gestionActual}...");

		$idDatosMora = DB::table('datos_mora')->insertGetId([
			'gestion' => $gestionActual,
			'tipo_calculo' => 'MONTO_FIJO',
			'monto' => 50.00, // 50 Bs de mora
			'activo' => true,
			'created_at' => now(),
			'updated_at' => now(),
		]);

		$this->command->info("  ✓ Configuración general creada (ID: {$idDatosMora})");

		// 2. Crear configuración detallada por semestre
		$this->command->info("\n📋 Creando configuración detallada por semestre...");

		$configuraciones = [
			// Configuración por semestre
			['semestre' => '1', 'monto' => 50.00],
			['semestre' => '2', 'monto' => 50.00],
			['semestre' => '3', 'monto' => 50.00],
			['semestre' => '4', 'monto' => 50.00],
			['semestre' => '5', 'monto' => 50.00],
			['semestre' => '6', 'monto' => 50.00],
		];

		$insertados = 0;
		foreach ($configuraciones as $config) {
			DB::table('datos_mora_detalle')->insert([
				'id_datos_mora' => $idDatosMora,
				'semestre' => $config['semestre'],
				'id_cuota' => null, // Aplica a todas las cuotas del semestre
				'monto' => $config['monto'],
				'fecha_inicio' => null, // Vigente desde siempre
				'fecha_fin' => null, // Sin fecha de fin
				'activo' => true,
				'created_at' => now(),
				'updated_at' => now(),
			]);
			$insertados++;
			$this->command->info("  ✓ Semestre {$config['semestre']}: Monto {$config['monto']} Bs");
		}

		$this->command->info("\n✅ Configuración de moras completada:");
		$this->command->info("   - Gestión: {$gestionActual}");
		$this->command->info("   - Tipo de cálculo: MONTO_FIJO (50 Bs)");
		$this->command->info("   - Configuraciones por semestre: {$insertados}");

		$this->command->info("\n📌 Reglas configuradas:");
		$this->command->info("   • Todos los semestres: 50 Bs de mora");
	}
}
