/**
 * Fechas "solo día" sin desfase UTC: `new Date('2025-07-11')` es medianoche UTC y al
 * usar setHours local puede quedar el día anterior (p. ej. América/La_Paz).
 */

export function startOfLocalDay(d: Date = new Date()): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

/**
 * Parsea "YYYY-MM-DD" como calendario local (medianoche en la zona del navegador).
 * Devuelve null si el formato no es válido o la fecha no existe.
 */
export function parseDateOnlyLocal(value: string | null | undefined): Date | null {
  const s = (value ?? '').trim();
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
  if (!m) return null;
  const y = Number(m[1]);
  const mo = Number(m[2]) - 1;
  const d = Number(m[3]);
  const dt = new Date(y, mo, d);
  if (dt.getFullYear() !== y || dt.getMonth() !== mo || dt.getDate() !== d) {
    return null;
  }
  return dt;
}

/** Diferencia en días entre dos instantes (puede ser negativa). */
export function diffCalendarDaysUtc(from: Date, to: Date): number {
  return Math.round((to.getTime() - from.getTime()) / (86_400_000));
}

/**
 * true si "hoy" (día local) está en o antes del día límite (cadena YYYY-MM-DD).
 */
export function isOnOrBeforeDeadlineLocal(deadlineYmd: string | null | undefined): boolean {
  if (!deadlineYmd) return true;
  const limite = parseDateOnlyLocal(deadlineYmd);
  if (!limite) return true;
  const hoy = startOfLocalDay();
  return hoy.getTime() <= limite.getTime();
}
