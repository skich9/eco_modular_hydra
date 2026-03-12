<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixAsignacionFuncionAndRolFuncionSchema extends Migration
{
	public function up()
	{
		if (Schema::hasTable('asignacion_funcion')) {
			Schema::table('asignacion_funcion', function (Blueprint $table) {
				if (!Schema::hasColumn('asignacion_funcion', 'activo')) {
					$table->boolean('activo')->default(true)->after('fecha_fin');
				}
				if (!Schema::hasColumn('asignacion_funcion', 'observaciones')) {
					$table->text('observaciones')->nullable()->after('activo');
				}
				if (!Schema::hasColumn('asignacion_funcion', 'asignado_por')) {
					$table->unsignedBigInteger('asignado_por')->nullable()->after('observaciones');
				}
			});

			Schema::table('asignacion_funcion', function (Blueprint $table) {
				if (Schema::hasColumn('asignacion_funcion', 'asignado_por')) {
					$fkExists = null;
					try {
						$fkExists = DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignacion_funcion' AND COLUMN_NAME = 'asignado_por' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
					} catch (\Throwable $e) {
						$fkExists = null;
					}

					if (empty($fkExists)) {
						try {
							$table->foreign('asignado_por', 'asignacion_funcion_asignado_por_foreign')
								->references('id_usuario')
								->on('usuarios')
								->onDelete('set null');
						} catch (\Throwable $e) {
						}
					}
				}
			});
		}

		if (!Schema::hasTable('rol_funcion')) {
			Schema::create('rol_funcion', function (Blueprint $table) {
				$table->id('id_rol_funcion');
				$table->unsignedBigInteger('id_rol');
				$table->unsignedBigInteger('id_funcion');
				$table->timestamps();

				$table->unique(['id_rol', 'id_funcion'], 'unique_rol_funcion');
				$table->index('id_funcion', 'rol_funcion_id_funcion_foreign');

				$table->foreign('id_rol')->references('id_rol')->on('rol')->onDelete('cascade')->onUpdate('restrict');
				$table->foreign('id_funcion')->references('id_funcion')->on('funciones')->onDelete('cascade')->onUpdate('restrict');
			});
		}
	}

	public function down()
	{
		if (Schema::hasTable('asignacion_funcion')) {
			Schema::table('asignacion_funcion', function (Blueprint $table) {
				if (Schema::hasColumn('asignacion_funcion', 'asignado_por')) {
					try {
						$table->dropForeign(['asignado_por']);
					} catch (\Throwable $e) {
					}
				}

				$cols = [];
				if (Schema::hasColumn('asignacion_funcion', 'asignado_por')) {
					$cols[] = 'asignado_por';
				}
				if (Schema::hasColumn('asignacion_funcion', 'observaciones')) {
					$cols[] = 'observaciones';
				}
				if (Schema::hasColumn('asignacion_funcion', 'activo')) {
					$cols[] = 'activo';
				}
				if (!empty($cols)) {
					$table->dropColumn($cols);
				}
			});
		}

		if (Schema::hasTable('rol_funcion')) {
			Schema::dropIfExists('rol_funcion');
		}
	}

}
