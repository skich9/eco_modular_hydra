<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinDatosSincronizacionSeeder extends Seeder
{
	public function run()
	{
		$backup = base_path('angular_laravel_project_con_datos.sql');
		if (!file_exists($backup)) {
			throw new \RuntimeException('No se encontró el archivo de backup SQL: ' . $backup);
		}

		$sql = file_get_contents($backup);
		$pattern = '/INSERT INTO `sin_datos_sincronizacion` VALUES \(.*?\);/ms';
		if (!preg_match_all($pattern, $sql, $matches)) {
			return;
		}

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('sin_datos_sincronizacion')->truncate();
		foreach ($matches[0] as $stmt) {
			DB::unprepared($stmt);
		}
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
