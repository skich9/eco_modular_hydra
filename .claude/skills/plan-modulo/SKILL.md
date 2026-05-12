---
name: plan-modulo
description: Usar cuando el usuario quiere planificar una funcionalidad antes de implementar, o cuando la tarea es suficientemente compleja para necesitar un plan. Activar con frases como "planifica", "antes de implementar", "cómo lo harías", "qué archivos tocarías", "dame un plan". Esta skill entra automáticamente en plan mode.
version: 1.0.0
disable-model-invocation: true
---

# Skill: Planificar módulo/funcionalidad

## Instrucción

Entrar en plan mode (`/plan`) para esta tarea.

## Qué debe incluir un buen plan en este proyecto

### Sección Contexto
- Por qué se necesita este cambio (problema que resuelve)
- Referencia al módulo del SGA si existe (`c:\gustavo\sga\`)
- Archivos actuales que se verán afectados

### Sección Archivos a crear/modificar
Lista concreta con rutas absolutas:
- `src/app/Http/Controllers/Api/Economico/NombreController.php` — qué métodos
- `src/app/Services/Economico/NombreService.php` — qué lógica
- `frontend/src/app/services/nombre.service.ts` — qué endpoints
- `frontend/src/app/components/pages/cobros/nombre/` — qué UI

### Sección BD (si aplica)
- Nombre de tabla y columnas con tipos
- Relaciones con otras tablas existentes

### Sección PDF (si aplica)
- Librería: mPDF (NO cambiar)
- Qué va en header, body, footer

### Sección Verificación
Pasos concretos para confirmar que funciona:
1. Correr migración
2. Abrir la pantalla en la UI
3. Probar el flujo completo (crear, listar, imprimir si aplica)
4. Verificar en BD que los datos se guardaron

## Recordatorio de preferencias del usuario

- El usuario prefiere entender el "por qué" de cada decisión técnica
- Proponer el enfoque con la razón, no solo el resultado
- Si hay alternativas, mencionar la recomendada y el trade-off principal
- No implementar hasta que el plan esté aprobado explícitamente
