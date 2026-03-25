/**
 * Montos de costos: solo enteros > 0 (sin decimales, null ni vacío).
 */

/** Solo dígitos (sin punto ni coma). */
export function sanitizeIntegerString(input: string): string {
  return (input ?? '').replace(/\D/g, '');
}

/**
 * Entero positivo estricto (cadena solo dígitos, sin parte decimal).
 */
export function parsePositiveInteger(raw: unknown): number | null {
  if (raw === null || raw === undefined) return null;
  const s = String(raw).trim();
  if (s === '' || !/^\d+$/.test(s)) return null;
  const n = parseInt(s, 10);
  if (!Number.isFinite(n) || n <= 0) return null;
  return n;
}
