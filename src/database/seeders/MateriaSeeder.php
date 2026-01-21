<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MateriaSeeder extends Seeder
{
	public function run()
	{
		$sql = require database_path('seeders' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'materia_data.php');

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('materia')->truncate();
		DB::unprepared($sql);
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
