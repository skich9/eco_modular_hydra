# Documentación de Rutas - Sistema CETA

## Estructura General

El sistema Angular implementa una estructura de rutas organizada en base a las siguientes características:

1. **Autenticación y Protección**: 
   - Rutas públicas (no requieren autenticación)
   - Rutas protegidas (requieren autenticación mediante guards)

2. **Componente Layout**:
   - Todas las rutas protegidas utilizan un componente layout común que incluye la barra lateral (sidebar) y otras estructuras compartidas

## Rutas Públicas

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/login` | `LoginComponent` | Pantalla de inicio de sesión |
| `/api-test` | `ApiTestComponent` | Componente de prueba para verificar conexión API |

## Rutas Protegidas

Todas estas rutas requieren autenticación y están anidadas dentro del componente `LayoutComponent`.

### Dashboard

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/dashboard` | `DashboardComponent` | Panel principal de la aplicación |

### Gestión de Usuarios

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/usuarios` | `UsuariosListComponent` | Listado de usuarios |
| `/usuarios/nuevo` | `UsuarioFormComponent` | Formulario para crear nuevo usuario |
| `/usuarios/editar/:id` | `UsuarioFormComponent` | Formulario para editar usuario existente |

### Gestión de Materias

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/materias` | `MateriasListComponent` | Listado de materias académicas |
| `/materias/nuevo` | `MateriaFormComponent` | Formulario para crear nueva materia |
| `/materias/editar/:sigla` | `MateriaFormComponent` | Formulario para editar materia existente |

### Gestión de Roles

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/roles` | `RolesListComponent` | Listado de roles del sistema |
| `/roles/nuevo` | `RolFormComponent` | Formulario para crear nuevo rol |
| `/roles/editar/:id` | `RolFormComponent` | Formulario para editar rol existente |

### Gestión de Parámetros del Sistema

| Ruta | Componente | Descripción |
|------|------------|-------------|
| `/parametros` | `ParametrosSistemaFormComponent` | Listado de parámetros del sistema |
| `/parametros/nuevo` | `ParametrosSistemaFormComponent` | Formulario para crear nuevo parámetro |
| `/parametros/editar/:id` | `ParametrosSistemaFormComponent` | Formulario para editar parámetro existente |

## Guardias de Rutas (Guards)

El sistema utiliza dos guardias principales para proteger las rutas:

1. **authGuard**: Asegura que el usuario esté autenticado para acceder a rutas protegidas.
2. **publicOnlyGuard**: Asegura que usuarios ya autenticados no puedan acceder a rutas públicas como login.

## Comportamiento de Redirección

- Si el usuario no está autenticado e intenta acceder a una ruta protegida, será redirigido a `/login`.
- Si el usuario está autenticado e intenta acceder a `/login`, será redirigido a `/dashboard`.
- Cualquier ruta no definida será redirigida a `/dashboard` (ruta por defecto).

## Integración con el Sidebar

El componente sidebar (`SidebarComponent`) muestra enlaces dinámicos basados en:
- El estado de autenticación del usuario
- El rol del usuario actual (algunas opciones solo son visibles para administradores)

Este sistema de rutas facilita la navegación fluida entre los diferentes módulos de la aplicación mientras mantiene un control adecuado de autenticación y autorización.
