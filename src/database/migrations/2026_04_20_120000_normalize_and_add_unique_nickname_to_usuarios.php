<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Normaliza `usuarios.nickname` (trim + minúsculas) y agrega índice UNIQUE.
	 *
	 * Estrategia:
	 * 1) Preflight: si al normalizar quedarían duplicados lógicos, abortar con detalle
	 *    para que el administrador los resuelva manualmente (evita pérdida de datos).
	 * 2) Normalizar filas existentes: nickname = LOWER(TRIM(nickname)).
	 * 3) Agregar índice UNIQUE sobre nickname.
	 */
	public function up(): void
	{
		if (!Schema::hasTable('usuarios')) {
			return;
		}

		// 1) Detectar duplicados lógicos (tras normalizar)
		$duplicados = DB::table('usuarios')
			->selectRaw('LOWER(TRIM(nickname)) AS nick_norm, COUNT(*) AS total, GROUP_CONCAT(id_usuario) AS ids')
			->groupBy('nick_norm')
			->havingRaw('COUNT(*) > 1')
			->get();

		if ($duplicados->isNotEmpty()) {
			$detalle = $duplicados->map(function ($r) {
				return "'{$r->nick_norm}' (ids: {$r->ids})";
			})->implode('; ');

			throw new \RuntimeException(
				"No se puede aplicar UNIQUE a usuarios.nickname: existen duplicados lógicos tras normalizar. " .
				"Resuelve manualmente (renombrar o desactivar) y vuelve a migrar. Conflictos: {$detalle}"
			);
		}

		// 2) Normalizar valores existentes
		DB::statement("UPDATE usuarios SET nickname = LOWER(TRIM(nickname))");

		// 3) Crear índice UNIQUE (si no existiera ya)
		$existeUnique = collect(DB::select("SHOW INDEX FROM usuarios"))
			->contains(fn ($idx) => $idx->Key_name === 'usuarios_nickname_unique');

		if (!$existeUnique) {
			Schema::table('usuarios', function (Blueprint $table) {
				$table->unique('nickname', 'usuarios_nickname_unique');
			});
		}
	}

	/**
	 * Solo elimina el índice UNIQUE. La normalización a minúsculas no es reversible
	 * (no guardamos el casing original).
	 */
	public function down(): void
	{
		if (!Schema::hasTable('usuarios')) {
			return;
		}

		$existeUnique = collect(DB::select("SHOW INDEX FROM usuarios"))
			->contains(fn ($idx) => $idx->Key_name === 'usuarios_nickname_unique');

		if ($existeUnique) {
			Schema::table('usuarios', function (Blueprint $table) {
				$table->dropUnique('usuarios_nickname_unique');
			});
		}
	}
};
