---
name: migrar-modulo
description: Usar cuando el usuario quiere migrar un módulo, formulario o funcionalidad del SGA (sistema legacy en CodeIgniter/PHP) al sistemaEco (Angular + Laravel). Activar con frases como "migrar", "pasar del SGA", "el SGA tiene esto", "quiero que funcione como en el SGA", "analiza este formulario del SGA".
version: 1.0.0
---

# Skill: Migrar módulo del SGA → sistemaEco

## Contexto del proyecto

- **SGA** (sistema origen): CodeIgniter en `c:\gustavo\sga\` — vistas PHP en `application/views/`, controladores en `application/controllers/`, modelos en `application/models/`
- **sistemaEco** (sistema destino): Laravel + Angular en `c:\gustavo\proyecto\sistemaEco\`
  - Backend Laravel: `src/` — controladores en `src/app/Http/Controllers/Api/`, servicios en `src/app/Services/`, modelos en `src/app/Models/`, rutas en `src/routes/api.php`
  - Frontend Angular: `frontend/src/app/` — componentes en `components/pages/cobros/`, servicios en `services/`
- **Docker**: el contenedor PHP se llama `angular_laravel_php`

## Proceso de migración

### Fase 1 — Análisis del SGA (SIEMPRE primero)
1. Leer el controlador SGA del módulo (`application/controllers/...`)
2. Leer la vista SGA (`application/views/...`)
3. Leer el modelo SGA si existe
4. Identificar: qué datos muestra, qué endpoints consume, qué validaciones hace, qué PDF genera si aplica

### Fase 2 — Plan (entrar a plan mode con `/plan`)
Diseñar:
- **Angular**: componente + servicio + ruta en `app.routes.ts` + entrada en navegación
- **Laravel**: controlador API + servicio + modelo si hay BD nueva + migración si aplica + rutas en `api.php`
- **PDF** si el módulo lo genera: servicio PDF con mPDF + Blade template simplificado

### Fase 3 — Implementación (después de aprobación del plan)
Crear en este orden:
1. Migración de BD (si aplica) → correr en Docker
2. Modelo Laravel
3. Servicio Laravel (lógica de negocio, sin SQL directo)
4. Controlador Laravel API (solo orquesta, delega al servicio)
5. Rutas en `api.php`
6. Servicio Angular (`*.service.ts`)
7. Componente Angular (`*.component.ts` + `.html` + `.scss`)
8. Ruta en `app.routes.ts`
9. Entrada en navegación (`navigation.component.html`)

## Patrones del proyecto a respetar

- Controladores API delegan toda la lógica al servicio — nunca SQL directo en el controlador
- Servicios Angular usan `HttpClient` con `Observable<T>`, los componentes usan `firstValueFrom()`
- Componentes standalone con `CommonModule + FormsModule`
- Estilos: usar las clases `.page-wrap`, `.card-panel`, `.data-table`, `.field`, `.btn` ya definidas globalmente — NO inventar CSS nuevo salvo casos específicos
- PDF: usar mPDF con `SetHTMLHeader()` / `SetHTMLFooter()` — NO Dompdf, NO paginación manual en Blade

## Referencia de archivos clave

- Patrón de servicio Angular: `frontend/src/app/services/reporte-caja-fuerte.service.ts`
- Patrón de componente Angular: `frontend/src/app/components/pages/cobros/reporte-caja-fuerte/`
- Patrón de controlador Laravel: `src/app/Http/Controllers/Api/Economico/ReporteCajaFuerteController.php`
- Patrón de servicio Laravel: `src/app/Services/Economico/ReporteCajaFuerteService.php`
- Patrón PDF mPDF: `src/app/Services/Economico/ReporteCajaFuertePdfService.php`
- Patrón Blade PDF: `src/resources/views/pdf/reporte_caja_fuerte.blade.php`
