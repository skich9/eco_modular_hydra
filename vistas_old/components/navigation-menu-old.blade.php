<nav class="bg-blue-600 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Logo y título -->
            <div class="flex items-center">
                <img src="{{ asset('images/logo-ceta.png') }}" alt="Logo CETA" class="h-8 w-8 mr-3">
                <span class="text-white font-bold text-lg">Sistema de Cobros CETA</span>
            </div>

            <!-- Menú principal -->
            <div class="hidden md:flex space-x-4">
                @foreach ($menuItems as $item)
                    <div class="relative group">
                        <button class="text-white hover:bg-blue-700 px-3 py-2 rounded-md flex items-center">
                            <i class="fas {{ $item['icon'] }} mr-2"></i>
                            {{ $item['name'] }}
                            <i class="fas fa-chevron-down ml-1 text-xs"></i>
                        </button>
                        <!-- Submenú desplegable -->
                        <div class="absolute hidden group-hover:block bg-white rounded-md shadow-lg py-1 z-50 min-w-[200px]">
                            @foreach ($item['submenu'] as $subitem)
                                <a href="{{ route($subitem['route']) }}" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">
                                    <i class="fas {{ $subitem['icon'] }} mr-2"></i>
                                    {{ $subitem['name'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Menú usuario -->
            <div class="flex items-center space-x-4">
                <span class="text-white text-sm">{{ $usuario['nombre_completo'] }} ({{ $usuario['rol_nombre'] }})</span>
                <div class="relative">
                    <button onclick="toggleUserMenu()" class="h-8 w-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-medium">
                        {{ substr($usuario['nombre_completo'], 0, 1) }}
                    </button>
                    <div id="userMenu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="{{ route('change-password') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-key mr-2"></i> Cambiar contraseña
                        </a>
                        <a href="{{ route('logout') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2"></i> Cerrar sesión
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

@push('scripts')
<script>
    function toggleSubmenu(id) {
        document.getElementById(id).classList.toggle('hidden');
    }
</script>
@endpush

