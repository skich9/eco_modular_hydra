---
name: nuevo-modulo
description: Usar cuando el usuario quiere crear un módulo completamente nuevo en sistemaEco que no existe en el SGA. Activar con frases como "crear un nuevo módulo", "necesito una pantalla para", "agregar funcionalidad de", "nuevo formulario para". Si hay referencia en el SGA, usar la skill migrar-modulo en su lugar.
version: 1.0.0
---

# Skill: Crear nuevo módulo en sistemaEco

## Checklist de archivos a crear

### Backend Laravel (`src/`)
- [ ] Migración: `src/database/migrations/YYYY_MM_DD_HHMMSS_create_*.php`
- [ ] Modelo: `src/app/Models/NombreModelo.php`
- [ ] Servicio: `src/app/Services/Economico/NombreService.php`
- [ ] Controlador: `src/app/Http/Controllers/Api/Economico/NombreController.php`
- [ ] Rutas en: `src/routes/api.php`

### Frontend Angular (`frontend/src/app/`)
- [ ] Servicio: `services/nombre.service.ts`
- [ ] Componente: `components/pages/cobros/nombre/nombre.component.ts`
- [ ] Template: `components/pages/cobros/nombre/nombre.component.html`
- [ ] Estilos: `components/pages/cobros/nombre/nombre.component.scss`
- [ ] Ruta en: `app/app.routes.ts`
- [ ] Entrada navegación: `components/shared/navigation/navigation.component.html`

## Convenciones obligatorias

### Laravel
- Controlador solo orquesta (valida request → llama servicio → retorna JSON)
- Servicio contiene toda la lógica de negocio
- Modelos usan `$fillable` explícito, no `$guarded = []`
- Rutas bajo el grupo `api/economico/` con middleware `auth:sanctum`
- Nombrado de rutas REST: `initial`, `store`, `update`, `destroy`, más verbos específicos si aplica

### Angular
- Componentes standalone: `imports: [CommonModule, FormsModule, DecimalPipe]`
- Servicio usa `HttpClient` con `Observable<T>` tipado — `firstValueFrom()` en el componente
- Estado UI: `cargando`, `guardando`, `alertMsg`, `alertOk` como patrón estándar
- Validación simple con flags `invalid*` booleanos — no reactive forms

### Estilos (NO escribir CSS nuevo si ya existe la clase)
Clases globales disponibles:
- Layout: `.page-wrap`, `.page-header`, `.card-panel`, `.card-panel--form`, `.card-panel--table`
- Formulario: `.form-grid`, `.field`, `.field-label`, `.field-control`, `.req`, `.invalid-msg`, `.form-actions`
- Tabla: `.data-table`, `.table-header`, `.table-actions`, `.table-badges`
- Botones: `.btn`, `.btn-primary`, `.btn-success`, `.btn-danger`, `.btn-outline-secondary`
- Alertas: `.alert`, `.alert-success`, `.alert-danger`
- Modal: `.modal-backdrop`, `.modal-box`, `.modal-desc`, `.modal-actions`

## Proceso

1. Clarificar con el usuario: ¿qué datos maneja?, ¿qué acciones (crear/editar/eliminar/imprimir)?, ¿hay tabla en BD o es solo consulta?
2. Entrar a `/plan` para diseñar antes de codificar
3. Implementar en el orden del checklist (BD primero, frontend último)
4. Correr migración: `docker exec angular_laravel_php php artisan migrate`
5. Verificar en la UI que el módulo funciona end-to-end
