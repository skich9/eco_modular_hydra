<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Str;

/**
 * Quién puede listar y gestionar el directorio completo de usuarios (API mantenimiento),
 * independiente del módulo Libro Diario.
 *
 * Ampliar {@see ROLES_DIRECTORIO_USUARIOS_COMPLETO} para dar el mismo alcance que la
 * heurística histórica "admin" a otros roles (p. ej. sistemas).
 */
final class UsuarioDirectoryAccessService
{
	/**
	 * Nombres de rol en BD, comparados tras {@see normalizarClaveRol}.
	 *
	 * @var list<string>
	 */
	private const ROLES_DIRECTORIO_USUARIOS_COMPLETO = [
		'sistemas',
		#'rector',
		#'tesoreria',
		#'contabilidad',
		];

	public static function puedeGestionarDirectorioCompletoDeUsuarios(Usuario $authUser): bool
	{
		if (strtolower((string) $authUser->nickname) === 'admin') {
			return true;
		}

		$authUser->loadMissing('rol');
		$rolNombre = strtolower((string) optional($authUser->rol)->nombre);
		if (str_contains($rolNombre, 'admin')) {
			return true;
		}

		$nombre = trim((string) optional($authUser->rol)->nombre);
		if ($nombre === '') {
			return false;
		}

		$clave = self::normalizarClaveRol($nombre);

		return in_array($clave, self::ROLES_DIRECTORIO_USUARIOS_COMPLETO, true);
	}

	private static function normalizarClaveRol(string $nombre): string
	{
		$s = Str::ascii(trim($nombre));
		$s = strtolower(preg_replace('/\s+/u', ' ', $s) ?? $s);

		return $s;
	}
}
