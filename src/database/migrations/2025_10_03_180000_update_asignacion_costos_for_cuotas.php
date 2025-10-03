<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}

		Schema::table('asignacion_costos', function (Blueprint $table) {
			// Nuevos campos para manejar cuotas
			if (!Schema::hasColumn('asignacion_costos', 'numero_cuota')) {
				$table->smallInteger('numero_cuota')->nullable()->after('id_compromisos');
			}
			if (!Schema::hasColumn('asignacion_costos', 'fecha_vencimiento')) {
				$table->date('fecha_vencimiento')->nullable()->after('numero_cuota');
			}
			if (!Schema::hasColumn('asignacion_costos', 'estado_pago')) {
				$table->string('estado_pago', 30)->default('pendiente')->after('fecha_vencimiento');
			}
			if (!Schema::hasColumn('asignacion_costos', 'fecha_pago')) {
				$table->date('fecha_pago')->nullable()->after('estado_pago');
			}
			if (!Schema::hasColumn('asignacion_costos', 'monto_pagado')) {
				$table->decimal('monto_pagado', 10, 2)->default(0)->after('fecha_pago');
			}
			if (!Schema::hasColumn('asignacion_costos', 'id_cuota_template')) {
				$table->unsignedBigInteger('id_cuota_template')->nullable()->after('monto_pagado');
			}
		});

		// Quitar UNIQUE anterior si existe (cod_pensum, cod_inscrip, id_costo_semestral)
		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				$table->dropUnique('asignacion_costos_unique');
			});
		} catch (\Throwable $e) {}

		// Crear UNIQUE nuevo incluyendo numero_cuota
		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				$table->unique(['cod_pensum','cod_inscrip','id_costo_semestral','numero_cuota'], 'asignacion_costos_unique_cuota');
			});
		} catch (\Throwable $e) {}

		// Índices útiles
		Schema::table('asignacion_costos', function (Blueprint $table) {
			if (!Schema::hasColumn('asignacion_costos', 'fecha_vencimiento')) return; // seguro
			$table->index('fecha_vencimiento', 'idx_asig_fecha_venc');
			$table->index('estado_pago', 'idx_asig_estado_pago');
			$table->index('id_costo_semestral', 'idx_asig_id_costo_sem');
		});
	}

	public function down(): void
	{
		if (!Schema::hasTable('asignacion_costos')) {
			return;
		}
		// Revertir índices y unique nuevos
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropUnique('asignacion_costos_unique_cuota'); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropIndex('idx_asig_fecha_venc'); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropIndex('idx_asig_estado_pago'); }); } catch (\Throwable $e) {}
		try { Schema::table('asignacion_costos', function (Blueprint $table) { $table->dropIndex('idx_asig_id_costo_sem'); }); } catch (\Throwable $e) {}

		// Eliminar columnas nuevas
		Schema::table('asignacion_costos', function (Blueprint $table) {
			foreach (['id_cuota_template','monto_pagado','fecha_pago','estado_pago','fecha_vencimiento','numero_cuota'] as $col) {
				if (Schema::hasColumn('asignacion_costos', $col)) {
					$table->dropColumn($col);
				}
			}
		});

		// Restaurar unique anterior (si aplica)
		try {
			Schema::table('asignacion_costos', function (Blueprint $table) {
				$table->unique(['cod_pensum','cod_inscrip','id_costo_semestral'], 'asignacion_costos_unique');
			});
		} catch (\Throwable $e) {}
	}
};
