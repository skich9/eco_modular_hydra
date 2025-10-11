<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	private function indexExists(string $table, string $index): bool
	{
		$database = DB::getDatabaseName();
		return DB::table('information_schema.statistics')
			->where('table_schema', $database)
			->where('table_name', $table)
			->where('index_name', $index)
			->exists();
	}

	private function isUnsignedBigInt(string $table, string $column): bool
	{
		$database = DB::getDatabaseName();
		return DB::table('information_schema.columns')
			->where('table_schema', $database)
			->where('table_name', $table)
			->where('column_name', $column)
			->where('data_type', 'bigint')
			->where('column_type', 'like', '%unsigned%')
			->exists();
	}

	private function foreignKeyExists(string $table, string $constraint): bool
	{
		$database = DB::getDatabaseName();
		return DB::table('information_schema.table_constraints as tc')
			->join('information_schema.key_column_usage as kcu', function ($join) {
				$join->on('tc.constraint_name', '=', 'kcu.constraint_name')
					->on('tc.table_name', '=', 'kcu.table_name')
					->on('tc.table_schema', '=', 'kcu.table_schema');
			})
			->where('tc.constraint_type', 'FOREIGN KEY')
			->where('tc.constraint_name', $constraint)
			->where('tc.table_schema', $database)
			->where('tc.table_name', $table)
			->exists();
	}

	public function up(): void
	{
		// Regular - Paso 1: columnas
		Schema::table('cobros_detalle_regular', function (Blueprint $table) {
			if (!Schema::hasColumn('cobros_detalle_regular', 'cod_ceta')) {
				$table->unsignedBigInteger('cod_ceta')->nullable()->after('nro_cobro');
			} else {
				$table->unsignedBigInteger('cod_ceta')->nullable()->change();
			}
			if (!Schema::hasColumn('cobros_detalle_regular', 'cod_pensum')) {
				$table->string('cod_pensum', 50)->nullable()->after('cod_ceta');
			}
			if (!Schema::hasColumn('cobros_detalle_regular', 'tipo_inscripcion')) {
				$table->string('tipo_inscripcion', 255)->nullable()->after('cod_pensum');
			}
		});

		// Regular - Paso 2: índices y FK (solo si tipos alineados)
		if ($this->isUnsignedBigInt('cobros_detalle_regular', 'cod_ceta')) {
			Schema::table('cobros_detalle_regular', function (Blueprint $table) {
				if (!Schema::hasColumn('cobros_detalle_regular', 'cod_ceta')) return; // safety
				if (!$this->indexExists('cobros_detalle_regular', 'ux_cdr_contexto_nro_cobro')) {
					$table->unique(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'], 'ux_cdr_contexto_nro_cobro');
				}
				if (!$this->foreignKeyExists('cobros_detalle_regular', 'fk_cdr_cobro')) {
					$table->foreign(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'], 'fk_cdr_cobro')
						->references(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'])->on('cobro')
						->onUpdate('cascade')->onDelete('cascade');
				}
			});
		}

		// Multa - Paso 1: columnas
		Schema::table('cobros_detalle_multa', function (Blueprint $table) {
			if (!Schema::hasColumn('cobros_detalle_multa', 'cod_ceta')) {
				$table->unsignedBigInteger('cod_ceta')->nullable()->after('nro_cobro');
			} else {
				$table->unsignedBigInteger('cod_ceta')->nullable()->change();
			}
			if (!Schema::hasColumn('cobros_detalle_multa', 'cod_pensum')) {
				$table->string('cod_pensum', 50)->nullable()->after('cod_ceta');
			}
			if (!Schema::hasColumn('cobros_detalle_multa', 'tipo_inscripcion')) {
				$table->string('tipo_inscripcion', 255)->nullable()->after('cod_pensum');
			}
		});

		// Multa - Paso 2: índices y FK (solo si tipos alineados)
		if ($this->isUnsignedBigInt('cobros_detalle_multa', 'cod_ceta')) {
			Schema::table('cobros_detalle_multa', function (Blueprint $table) {
				if (!Schema::hasColumn('cobros_detalle_multa', 'cod_ceta')) return; // safety
				if (!$this->indexExists('cobros_detalle_multa', 'ux_cdm_contexto_nro_cobro')) {
					$table->unique(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'], 'ux_cdm_contexto_nro_cobro');
				}
				if (!$this->foreignKeyExists('cobros_detalle_multa', 'fk_cdm_cobro')) {
					$table->foreign(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'], 'fk_cdm_cobro')
						->references(['cod_ceta','cod_pensum','tipo_inscripcion','nro_cobro'])->on('cobro')
						->onUpdate('cascade')->onDelete('cascade');
				}
			});
		}
	}

	public function down(): void
	{
		Schema::table('cobros_detalle_regular', function (Blueprint $table) {
			if ($this->foreignKeyExists('cobros_detalle_regular', 'fk_cdr_cobro')) {
				$table->dropForeign('fk_cdr_cobro');
			}
			if ($this->indexExists('cobros_detalle_regular', 'ux_cdr_contexto_nro_cobro')) {
				$table->dropUnique('ux_cdr_contexto_nro_cobro');
			}
			if (Schema::hasColumn('cobros_detalle_regular', 'cod_ceta')) {
				$table->dropColumn('cod_ceta');
			}
			if (Schema::hasColumn('cobros_detalle_regular', 'cod_pensum')) {
				$table->dropColumn('cod_pensum');
			}
			if (Schema::hasColumn('cobros_detalle_regular', 'tipo_inscripcion')) {
				$table->dropColumn('tipo_inscripcion');
			}
		});

		Schema::table('cobros_detalle_multa', function (Blueprint $table) {
			if ($this->foreignKeyExists('cobros_detalle_multa', 'fk_cdm_cobro')) {
				$table->dropForeign('fk_cdm_cobro');
			}
			if ($this->indexExists('cobros_detalle_multa', 'ux_cdm_contexto_nro_cobro')) {
				$table->dropUnique('ux_cdm_contexto_nro_cobro');
			}
			if (Schema::hasColumn('cobros_detalle_multa', 'cod_ceta')) {
				$table->dropColumn('cod_ceta');
			}
			if (Schema::hasColumn('cobros_detalle_multa', 'cod_pensum')) {
				$table->dropColumn('cod_pensum');
			}
			if (Schema::hasColumn('cobros_detalle_multa', 'tipo_inscripcion')) {
				$table->dropColumn('tipo_inscripcion');
			}
		});
	}
};
