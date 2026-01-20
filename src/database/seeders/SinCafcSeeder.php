<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SinCafcSeeder extends Seeder
{
	public function run()
	{
		DB::table('sin_cafc')->insert([
			[
				'codigo_cafc' => 1,
				'cafc' => '1113C3484541E',
				'fecha_creacion' => '2024-05-26',
				'num_minimo' => 1,
				'num_maximo' => 50,
			]
		]);
	}
}
