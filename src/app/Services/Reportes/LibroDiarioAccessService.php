<?php

namespace App\Services\Reportes;

use App\Models\Usuario;
use Illuminate\Support\Str;

/**
 * Visibilidad del Libro Diario:
 * - Roles en {@see ROLES_LIBRO_DIARIO_VER_TODOS}: pueden consultar el libro de cualquier usuario activo.
 * - Cualquier otro rol: solo el propio usuario (incluye, entre otros: secretaría, taller, invitado,
 *   jefatura de carrera, asistente de rectorado, director académico, almacenero, administrador de taller,
 *   tienda, administrador, administrativo).
 *
 * Los nombres de rol en BD se comparan normalizados (minúsculas, sin acentos, espacios colapsados).
 * Si en BD el nombre no coincide exactamente con una clave de la lista global, ampliar
 * {@see ROLES_LIBRO_DIARIO_VER_TODOS} o usar un sinónimo normalizado.
 */
final class LibroDiarioAccessService
{
	/**
	 * Roles que pueden ver todos los libros diarios (rector, tesorería, contabilidad, sistemas).
	 *
	 * @var list<string> claves normalizadas con {@see normalizarClaveRol}
	 */
	private const ROLES_LIBRO_DIARIO_VER_TODOS = [
		'rector',
		'tesoreria',
		'contabilidad',
		'sistemas',
	];

	public static function rolPuedeVerTodosLosLibrosDiarios(Usuario $authUser): bool
	{
		$authUser->loadMissing('rol');
		$nombre = trim((string) optional($authUser->rol)->nombre);
		if ($nombre === '') {
			return false;
		}
		$clave = self::normalizarClaveRol($nombre);

		return in_array($clave, self::ROLES_LIBRO_DIARIO_VER_TODOS, true);
	}

	public static function puedeConsultarLibroDiarioDe(Usuario $authUser, int $targetUserId): bool
	{
		if ($targetUserId < 1) {
			return false;
		}
		if ((int) $authUser->id_usuario === $targetUserId) {
			return true;
		}
		$target = Usuario::query()->find($targetUserId);
		if (!$target) {
			return false;
		}
		return self::rolPuedeVerTodosLosLibrosDiarios($authUser);
	}

	/** Normaliza el nombre del rol para comparación estable con la lista permitida. */
	public static function normalizarClaveRol(string $nombre): string
	{
		$s = Str::ascii(trim($nombre));
		$s = strtolower(preg_replace('/\s+/u', ' ', $s) ?? $s);

		return $s;
	}
}
