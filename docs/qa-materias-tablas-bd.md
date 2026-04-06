# Pruebas QA — Creación/edición de materias (Académico)

## Tablas que intervienen

| Tabla | Rol |
|--------|-----|
| **`materia`** | Insert/update/delete del registro. Clave primaria compuesta `(sigla_materia, cod_pensum)`. Campos relevantes: `nivel_materia`, `nombre_materia`, `nombre_material_oficial`, `nro_creditos`, `orden`, `descripcion`, `activo` (o `estado` según esquema). |
| **`pensums`** | El `cod_pensum` de la materia debe existir; la pantalla Académico lista pensums por carrera (`codigo_carrera`). |
| **`carrera`** (o equivalente en tu esquema) | Define la carrera; la ruta `/academico/:codigo` filtra pensums por `codigo_carrera`. |

## Tablas relacionadas (no insert directo al crear materia)

| Tabla | Nota |
|--------|------|
| **`costo_materia`** | Puede tener FK a `materia(sigla_materia, cod_pensum)`; crear materia no inserta aquí automáticamente. |
| Otras (`cuotas`, `cobro`, etc.) | Usan `cod_pensum` o materias en otros contextos; no son obligatorias para el alta mínima. |

## Comprobaciones útiles en BD

- Tras crear: `SELECT * FROM materia WHERE cod_pensum = '...' AND sigla_materia = '...';`
- Validar que `nivel_materia` no quede NULL si el esquema lo exige.
