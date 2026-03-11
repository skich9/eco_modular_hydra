<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ModifyEstadoEnumInAsignacionMoraAddProrrogaStates extends Migration
{
	public function up()
	{
		if (!Schema::hasTable('asignacion_mora')) {
			return;
		}

		DB::statement("ALTER TABLE asignacion_mora MODIFY COLUMN estado ENUM('PENDIENTE','CONGELADA_PRORROGA','PAUSADA_DUPLICIDAD','CERRADA_SIN_CUOTA','PAGADO','CONDONADO','EN_ESPERA') NOT NULL DEFAULT 'PENDIENTE'");
	}

	public function down()
	{
		if (!Schema::hasTable('asignacion_mora')) {
			return;
		}

		DB::statement("ALTER TABLE asignacion_mora MODIFY COLUMN estado ENUM('PENDIENTE','PAGADO','CONDONADO','EN_ESPERA') NOT NULL DEFAULT 'PENDIENTE'");
	}
}
