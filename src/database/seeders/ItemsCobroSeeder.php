<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemsCobroSeeder extends Seeder
{
	public function run()
	{
		$sql = require database_path('seeders' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'items_cobro_data.php');

		DB::statement('SET FOREIGN_KEY_CHECKS=0');
		DB::table('items_cobro')->truncate();
		DB::unprepared($sql);
		DB::statement('SET FOREIGN_KEY_CHECKS=1');
	}
}
