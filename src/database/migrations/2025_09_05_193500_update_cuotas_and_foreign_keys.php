<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		// 1) Cuotas: agregar gestion y restriccion unica por nombre+gestion
		if (Schema::hasTable('cuotas')) {
			Schema::table('cuotas', function (Blueprint $table) {
				if (!Schema::hasColumn('cuotas', 'gestion')) {
					$table->string('gestion', 30)->after('monto')->nullable();
					// índice para gestionar calendarios por gestión
					$table->index('gestion', 'idx_cuotas_gestion');
				}
			});
		}

		// Índices/Únicos idempotentes
		if (Schema::hasTable('cuotas')) {
			if (!$this->indexExists('cuotas', 'uk_cuotas_nombre_gestion')) {
				DB::statement('CREATE UNIQUE INDEX `uk_cuotas_nombre_gestion` ON `cuotas` (`nombre`, `gestion`)');
			}
			if (!$this->indexExists('cuotas', 'idx_cuotas_fecha_venc')) {
				DB::statement('CREATE INDEX `idx_cuotas_fecha_venc` ON `cuotas` (`fecha_vencimiento`)');
			}
		}

		// 2) Llaves foraneas para enlazar correctamente el flujo de cuotas/prorrogas/multas
		if (Schema::hasTable('cobro') && Schema::hasTable('cuotas') && !$this->foreignKeyExists('cobro', 'fk_cobro_id_cuota')) {
			Schema::table('cobro', function (Blueprint $table) {
				// cobro.id_cuota -> cuotas.id_cuota
				$table->foreign('id_cuota', 'fk_cobro_id_cuota')
					->references('id_cuota')->on('cuotas')
					->onDelete('restrict')->onUpdate('restrict');
			});
		}

		// prorrogas.id_cuota (v5) o prorrogas.cuota_id (versiones previas) -> cuotas.id_cuota
		if (Schema::hasTable('prorrogas') && Schema::hasTable('cuotas') && Schema::hasColumn('prorrogas', 'id_cuota')) {
			if (!$this->foreignKeyExists('prorrogas', 'fk_prorrogas_id_cuota')) {
				Schema::table('prorrogas', function (Blueprint $table) {
					$table->foreign('id_cuota', 'fk_prorrogas_id_cuota')
						->references('id_cuota')->on('cuotas')
						->onDelete('restrict')->onUpdate('restrict');
				});
			}
		} elseif (Schema::hasTable('prorrogas') && Schema::hasTable('cuotas') && Schema::hasColumn('prorrogas', 'cuota_id')) {
			if (!$this->foreignKeyExists('prorrogas', 'fk_prorrogas_cuota_id')) {
				Schema::table('prorrogas', function (Blueprint $table) {
					$table->foreign('cuota_id', 'fk_prorrogas_cuota_id')
						->references('id_cuota')->on('cuotas')
						->onDelete('restrict')->onUpdate('restrict');
				});
			}
		}

		if (Schema::hasTable('recargo_mora') && Schema::hasTable('prorrogas') && !$this->foreignKeyExists('recargo_mora', 'fk_recargo_mora_id_prorroga')) {
			Schema::table('recargo_mora', function (Blueprint $table) {
				// recargo_mora.id_prorroga -> prorrogas.id_prorroga
				$table->foreign('id_prorroga', 'fk_recargo_mora_id_prorroga')
					->references('id_prorroga')->on('prorrogas')
					->onDelete('set null')->onUpdate('cascade');
			});
		}
		if (Schema::hasTable('recargo_mora') && Schema::hasTable('datos_mora_detalle') && !$this->foreignKeyExists('recargo_mora', 'fk_recargo_mora_id_datos_mora_detalle')) {
			Schema::table('recargo_mora', function (Blueprint $table) {
				// recargo_mora.id_datos_mora_detalle -> datos_mora_detalle.id_datos_mora_detalle
				$table->foreign('id_datos_mora_detalle', 'fk_recargo_mora_id_datos_mora_detalle')
					->references('id_datos_mora_detalle')->on('datos_mora_detalle')
					->onDelete('restrict')->onUpdate('restrict');
			});
		}

		if (Schema::hasTable('datos_mora_detalle') && Schema::hasTable('datos_mora') && !$this->foreignKeyExists('datos_mora_detalle', 'fk_datos_mora_detalle_id_datos_mora')) {
			Schema::table('datos_mora_detalle', function (Blueprint $table) {
				// datos_mora_detalle.id_datos_mora -> datos_mora.id_datos_mora
				$table->foreign('id_datos_mora', 'fk_datos_mora_detalle_id_datos_mora')
					->references('id_datos_mora')->on('datos_mora')
					->onDelete('cascade')->onUpdate('cascade');
			});
		}

		Schema::table('datos_mora', function (Blueprint $table) {
			// Evitamos FK por diferencia de longitud (datos_mora.gestion varchar(50) vs gestion.gestion varchar(30))
			// Añadimos índice para optimizar consultas por gestión si no existe
		});
		if (Schema::hasTable('datos_mora') && !$this->indexExists('datos_mora', 'idx_datos_mora_gestion')) {
			DB::statement('CREATE INDEX `idx_datos_mora_gestion` ON `datos_mora` (`gestion`)');
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		if (Schema::hasTable('cobro') && $this->foreignKeyExists('cobro', 'fk_cobro_id_cuota')) {
			Schema::table('cobro', function (Blueprint $table) {
				$table->dropForeign('fk_cobro_id_cuota');
			});
		}
		if (Schema::hasTable('prorrogas') && $this->foreignKeyExists('prorrogas', 'fk_prorrogas_id_cuota')) {
			Schema::table('prorrogas', function (Blueprint $table) {
				$table->dropForeign('fk_prorrogas_id_cuota');
			});
		}
		if (Schema::hasTable('prorrogas') && $this->foreignKeyExists('prorrogas', 'fk_prorrogas_cuota_id')) {
			Schema::table('prorrogas', function (Blueprint $table) {
				$table->dropForeign('fk_prorrogas_cuota_id');
			});
		}
		if (Schema::hasTable('recargo_mora') && $this->foreignKeyExists('recargo_mora', 'fk_recargo_mora_id_prorroga')) {
			Schema::table('recargo_mora', function (Blueprint $table) {
				$table->dropForeign('fk_recargo_mora_id_prorroga');
			});
		}
		if (Schema::hasTable('recargo_mora') && $this->foreignKeyExists('recargo_mora', 'fk_recargo_mora_id_datos_mora_detalle')) {
			Schema::table('recargo_mora', function (Blueprint $table) {
				$table->dropForeign('fk_recargo_mora_id_datos_mora_detalle');
			});
		}
		if (Schema::hasTable('datos_mora_detalle') && $this->foreignKeyExists('datos_mora_detalle', 'fk_datos_mora_detalle_id_datos_mora')) {
			Schema::table('datos_mora_detalle', function (Blueprint $table) {
				$table->dropForeign('fk_datos_mora_detalle_id_datos_mora');
			});
		}
		if ($this->indexExists('datos_mora', 'idx_datos_mora_gestion')) {
			DB::statement('DROP INDEX `idx_datos_mora_gestion` ON `datos_mora`');
		}
		if (Schema::hasTable('cuotas')) {
			Schema::table('cuotas', function (Blueprint $table) {
				if (Schema::hasColumn('cuotas', 'gestion')) {
					// drop unique si existe
				}
			});
		}
		if ($this->indexExists('cuotas', 'uk_cuotas_nombre_gestion')) {
			DB::statement('DROP INDEX `uk_cuotas_nombre_gestion` ON `cuotas`');
		}
		if ($this->indexExists('cuotas', 'idx_cuotas_gestion')) {
			DB::statement('DROP INDEX `idx_cuotas_gestion` ON `cuotas`');
		}
		if ($this->indexExists('cuotas', 'idx_cuotas_fecha_venc')) {
			DB::statement('DROP INDEX `idx_cuotas_fecha_venc` ON `cuotas`');
		}
		if (Schema::hasTable('cuotas')) {
			Schema::table('cuotas', function (Blueprint $table) {
				if (Schema::hasColumn('cuotas', 'gestion')) {
					$table->dropColumn('gestion');
				}
			});
		}
	}

	private function indexExists(string $table, string $indexName): bool
	{
		$db = DB::getDatabaseName();
		$result = DB::selectOne(
			"SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
			[$db, $table, $indexName]
		);
		return $result && (int) ($result->c ?? 0) > 0;
	}

	private function foreignKeyExists(string $table, string $foreignName): bool
	{
		$db = DB::getDatabaseName();
		$result = DB::selectOne(
			"SELECT COUNT(1) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
			[$db, $table, $foreignName]
		);
		return $result && (int) ($result->c ?? 0) > 0;
	}
};
