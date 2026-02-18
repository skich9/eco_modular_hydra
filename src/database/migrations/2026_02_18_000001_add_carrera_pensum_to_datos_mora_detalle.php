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
		// Paso 0: Buscar y eliminar TODAS las foreign keys que referencian a datos_mora_detalle desde otras tablas
		$externalFKs = DB::select("
			SELECT
				TABLE_NAME,
				CONSTRAINT_NAME
			FROM information_schema.KEY_COLUMN_USAGE
			WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
			AND REFERENCED_TABLE_NAME = 'datos_mora_detalle'
			AND CONSTRAINT_NAME != 'PRIMARY'
		");

		foreach ($externalFKs as $fk) {
			try {
				DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
			} catch (\Exception $e) {
				// Si falla, continuar
			}
		}

		// Paso 1: Eliminar TODAS las foreign keys de la tabla datos_mora_detalle
		$allFKs = DB::select("
			SELECT CONSTRAINT_NAME
			FROM information_schema.TABLE_CONSTRAINTS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'datos_mora_detalle'
			AND CONSTRAINT_TYPE = 'FOREIGN KEY'
		");

		foreach ($allFKs as $fk) {
			try {
				DB::statement("ALTER TABLE `datos_mora_detalle` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
			} catch (\Exception $e) {
				// Si falla, continuar
			}
		}

		// Paso 2: Ahora eliminar el índice único
		try {
			DB::statement('ALTER TABLE `datos_mora_detalle` DROP INDEX `uk_mora_detalle_semestre_cuota`');
		} catch (\Exception $e) {
			// Si falla, continuar
		}

		// Paso 3: Verificar si la columna id_cuota existe antes de renombrar
		$columnExists = DB::select("
			SELECT COLUMN_NAME
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'datos_mora_detalle'
			AND COLUMN_NAME = 'id_cuota'
		");

		if (!empty($columnExists)) {
			Schema::table('datos_mora_detalle', function (Blueprint $table) {
				$table->renameColumn('id_cuota', 'cuota');
			});
		}

		// Paso 4: Modificar el tipo de la columna cuota
		Schema::table('datos_mora_detalle', function (Blueprint $table) {
			$table->tinyInteger('cuota')->nullable()->comment('Número de cuota (1-5)')->change();
		});

		// Paso 5: Verificar si cod_pensum ya existe antes de agregarlo
		$codPensumExists = DB::select("
			SELECT COLUMN_NAME
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = 'datos_mora_detalle'
			AND COLUMN_NAME = 'cod_pensum'
		");

		if (empty($codPensumExists)) {
			Schema::table('datos_mora_detalle', function (Blueprint $table) {
				$table->string('cod_pensum', 50)->nullable()->after('semestre')->comment('Código del pensum');

				// Foreign key a tabla pensum
				$table->foreign('cod_pensum', 'fk_mora_detalle_pensum')
					->references('cod_pensum')->on('pensums')
					->onDelete('cascade')->onUpdate('cascade');

				// Nuevo índice único
				$table->unique(['id_datos_mora', 'semestre', 'cuota', 'cod_pensum'], 'uk_mora_detalle_config');

				// Índice para búsquedas por pensum
				$table->index('cod_pensum', 'idx_mora_detalle_pensum');
			});
		}

		Schema::table('datos_mora_detalle', function (Blueprint $table) {
			$table->foreign('id_datos_mora', 'fk_mora_detalle_datos_mora')
				->references('id_datos_mora')->on('datos_mora')
				->onDelete('cascade')->onUpdate('cascade');
		});

		// Paso 6: Recrear las foreign keys externas que fueron eliminadas
		foreach ($externalFKs as $fk) {
			try {
				// Obtener información de la FK original
				$fkInfo = DB::select("
					SELECT
						COLUMN_NAME,
						REFERENCED_COLUMN_NAME
					FROM information_schema.KEY_COLUMN_USAGE
					WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = ?
					AND CONSTRAINT_NAME = ?
				", [$fk->TABLE_NAME, $fk->CONSTRAINT_NAME]);

				if (!empty($fkInfo)) {
					$columnName = $fkInfo[0]->COLUMN_NAME;
					$referencedColumn = $fkInfo[0]->REFERENCED_COLUMN_NAME;

					// Recrear la FK apuntando a la nueva estructura
					DB::statement("
						ALTER TABLE `{$fk->TABLE_NAME}`
						ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}`
						FOREIGN KEY (`{$columnName}`)
						REFERENCES `datos_mora_detalle`(`{$referencedColumn}`)
						ON DELETE CASCADE
						ON UPDATE CASCADE
					");
				}
			} catch (\Exception $e) {

			}
		}
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('datos_mora_detalle', function (Blueprint $table) {
			// Eliminar índices y foreign key de cod_pensum
			$table->dropForeign('fk_mora_detalle_pensum');
			$table->dropUnique('uk_mora_detalle_config');
			$table->dropIndex('idx_mora_detalle_pensum');
			$table->dropColumn('cod_pensum');

			// Renombrar cuota de vuelta a id_cuota
			$table->renameColumn('cuota', 'id_cuota');
		});

		// Restaurar tipo de id_cuota
		Schema::table('datos_mora_detalle', function (Blueprint $table) {
			$table->unsignedBigInteger('id_cuota')->nullable()->comment('ID de la cuota específica')->change();

			// Restaurar foreign key de id_cuota
			$table->foreign('id_cuota', 'fk_mora_detalle_cuota')
				->references('id_cuota')->on('cuotas')
				->onDelete('cascade')->onUpdate('cascade');

			// Restaurar índice único original
			$table->unique(['id_datos_mora', 'semestre', 'id_cuota'], 'uk_mora_detalle_semestre_cuota');
		});
	}
};
