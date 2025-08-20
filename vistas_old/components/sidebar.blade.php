{{-- Sidebar Component - Menú lateral reutilizable --}}
<div class="card h-full min-h-screen w-64 fixed left-0 top-0 z-40 transform transition-transform duration-300 ease-in-out" id="sidebar">
    {{-- Header del Sidebar --}}
    <div class="flex items-center justify-center h-16" style="background-color: var(--primary-color);">
        <div class="flex items-center">
            <i class="fas fa-building text-2xl mr-3 text-white"></i>
            <span class="text-lg font-bold text-white">Sistema CETA</span>
        </div>
    </div>

    {{-- Información del Usuario --}}
    <div class="p-4 border-b">
        <div class="flex items-center">
            <div class="h-10 w-10 rounded-full flex items-center justify-center text-white font-medium" style="background-color: var(--primary-color);">
                {{ substr(session('usuario')['nombre_completo'] ?? 'U', 0, 1) }}
            </div>
            <div class="ml-3">
                <p class="text-sm font-bold">{{ session('usuario')['nombre_completo'] ?? 'Usuario' }}</p>
                <p class="text-xs text-secondary-color">{{ session('usuario')['rol_nombre'] ?? 'Sin rol' }}</p>
            </div>
        </div>
    </div>

    {{-- Navegación Principal --}}
    <nav class="mt-4">
        <div class="px-2">
            {{-- Dashboard --}}
            <a href="{{ route('dashboard') }}" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded {{ request()->routeIs('dashboard') ? 'bg-light-color text-primary-color' : 'hover:bg-light-color' }} transition-colors duration-200">
                <i class="fas fa-tachometer-alt mr-3 text-lg {{ request()->routeIs('dashboard') ? 'text-primary-color' : 'text-secondary-color' }}"></i>
                Dashboard
            </a>

            {{-- Gestión de Usuarios --}}
            @if(in_array('gestionar_usuarios', session('usuario')['funciones'] ?? []) || session('usuario')['rol_nombre'] === 'Administrador')
            <a href="{{ route('usuarios.index') }}" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded {{ request()->routeIs('usuarios.*') ? 'bg-light-color text-primary-color' : 'hover:bg-light-color' }} transition-colors duration-200">
                <i class="fas fa-users mr-3 text-lg {{ request()->routeIs('usuarios.*') ? 'text-primary-color' : 'text-secondary-color' }}"></i>
                Usuarios
            </a>
            @endif

            {{-- Gestión de Roles --}}
            @if(in_array('gestionar_roles', session('usuario')['funciones'] ?? []) || session('usuario')['rol_nombre'] === 'Administrador')
            <a href="{{ route('roles.index') }}" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded {{ request()->routeIs('roles.*') ? 'bg-light-color text-primary-color' : 'hover:bg-light-color' }} transition-colors duration-200">
                <i class="fas fa-user-tag mr-3 text-lg {{ request()->routeIs('roles.*') ? 'text-primary-color' : 'text-secondary-color' }}"></i>
                Roles
            </a>
            @endif

            {{-- Gestión de Funciones --}}
            @if(in_array('gestionar_funciones', session('usuario')['funciones'] ?? []) || session('usuario')['rol_nombre'] === 'Administrador')
            <a href="{{ route('funciones.index') }}" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded {{ request()->routeIs('funciones.*') ? 'bg-light-color text-primary-color' : 'hover:bg-light-color' }} transition-colors duration-200">
                <i class="fas fa-cogs mr-3 text-lg {{ request()->routeIs('funciones.*') ? 'text-primary-color' : 'text-secondary-color' }}"></i>
                Funciones
            </a>
            @endif

            {{-- Separador --}}
            <div class="border-t my-4"></div>

            {{-- Reportes --}}
            @if(in_array('ver_reportes', session('usuario')['funciones'] ?? []) || session('usuario')['rol_nombre'] === 'Administrador')
            <a href="#" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded hover:bg-light-color transition-colors duration-200">
                <i class="fas fa-chart-bar mr-3 text-lg text-secondary-color"></i>
                Reportes
            </a>
            @endif

            {{-- Configuración --}}
            @if(in_array('configurar_sistema', session('usuario')['funciones'] ?? []) || session('usuario')['rol_nombre'] === 'Administrador')
            <a href="#" 
               class="group flex items-center px-2 py-2 text-sm font-medium rounded hover:bg-light-color transition-colors duration-200">
                <i class="fas fa-cog mr-3 text-lg text-secondary-color"></i>
                Configuración
            </a>
            @endif
        </div>
    </nav>

    {{-- Footer del Sidebar --}}
    <div class="absolute bottom-0 w-full p-4 border-t">
        <div class="flex flex-col space-y-2">
            <a href="{{ route('change-password') }}" 
               class="flex items-center px-2 py-2 text-sm hover:bg-light-color rounded transition-colors duration-200">
                <i class="fas fa-key mr-3 text-secondary-color"></i>
                Cambiar Contraseña
            </a>
            <a href="{{ route('logout') }}" 
               class="flex items-center px-2 py-2 text-sm text-danger-color hover:bg-light-color rounded transition-colors duration-200">
                <i class="fas fa-sign-out-alt mr-3 text-danger-color"></i>
                Cerrar Sesión
            </a>
        </div>
    </div>
</div>

{{-- Overlay para móvil --}}
<div class="fixed inset-0 bg-dark-color bg-opacity-75 z-30 lg:hidden" id="sidebar-overlay" onclick="toggleSidebar()" style="display: none;"></div>

{{-- Script para toggle del sidebar en móvil --}}
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const mainContent = document.querySelector('.content-container');
    
    if (sidebar.classList.contains('-translate-x-full')) {
        // Mostrar sidebar
        sidebar.classList.remove('-translate-x-full');
        overlay.style.display = 'block';
        document.body.classList.add('overflow-hidden'); // Prevenir scroll
        
        // Ajustar margen del contenido principal en dispositivos grandes
        if (window.innerWidth >= 1024) {
            mainContent.style.marginLeft = '16rem'; // 64px (w-64)
        }
    } else {
        // Ocultar sidebar
        sidebar.classList.add('-translate-x-full');
        overlay.style.display = 'none';
        document.body.classList.remove('overflow-hidden');
        
        // Resetear margen en dispositivos grandes
        if (window.innerWidth >= 1024) {
            mainContent.style.marginLeft = '0';
        }
    }
}

// Inicialización cuando el DOM está listo
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    // Agregar clase para ocultar sidebar en móvil por defecto
    if (window.innerWidth < 1024) {
        sidebar.classList.add('-translate-x-full');
    }
    
    // Cerrar sidebar en móvil cuando se hace clic en un enlace
    const sidebarLinks = document.querySelectorAll('#sidebar a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 1024) {
                toggleSidebar();
            }
        });
    });
    
    // Ajustar sidebar al cambiar tamaño de ventana
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            // En pantallas grandes, mostrar sidebar y quitar overlay
            sidebar.classList.remove('-translate-x-full');
            overlay.style.display = 'none';
        } else {
            // En móviles, ocultar sidebar por defecto
            sidebar.classList.add('-translate-x-full');
        }
    });
});
</script>
