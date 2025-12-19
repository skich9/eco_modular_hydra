<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PensumResolucionSeeder extends Seeder
{
    public function run(): void
    {
        $resoluciones = [
            "04-MTZ" => "R.M. 066/2012",
            "04-MTZ-17" => "R.M. 082/2018",
            "EEA" => "R.M. 341/2012",
            "MEA" => "R.A. 513/2001",
            "04-MTZ-23" => "R.M. 210/2023",
            "EEA-19" => "R.M. 595/2019",
        ];

        foreach ($resoluciones as $codPensum => $resolucion) {
            DB::table("pensums")
                ->where("cod_pensum", $codPensum)
                ->update(["resolucion" => $resolucion]);
        }

        $this->command->info("Resoluciones de pensums actualizadas correctamente.");
    }
}
