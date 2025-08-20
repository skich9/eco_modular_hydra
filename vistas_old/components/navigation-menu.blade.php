<!-- Navigation Header -->
<nav class="nav-menu">
	<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
		<div class="flex justify-between h-16">
			<div class="flex items-center">
				<a href="{{ route('dashboard') }}" class="nav-logo">
					<div class="nav-logo-icon">
					<img src="{{ asset('images/logo-ceta.png') }}" alt="Logo CETA" class="login-logo-image">
					</div>
					<div class="nav-logo-text">
						<span class="nav-logo-title">Sistema de Cobros</span>
						<p class="nav-logo-subtitle">Instituto Tecnológico CETA</p>
					</div>
				</a>
			</div>

			<div class="nav-user-info">
				<div class="text-sm">
					<span class="nav-user-name">{{ $usuario['nombre_completo'] }}</span>
					<span class="nav-user-role">({{ $usuario['rol_nombre'] }})</span>
				</div>
				
				<div class="relative">
					<button 
						type="button" 
						class="flex items-center text-sm rounded-full focus:outline-none"
						onclick="toggleDropdown()"
					>
						<div class="nav-user-avatar">
							{{ substr($usuario['nombre_completo'], 0, 1) }}
						</div>
					</button>
					
					<div id="userDropdown" class="hidden nav-dropdown">
						<a href="{{ route('change-password') }}" class="nav-dropdown-item">
							<i class="fas fa-key mr-2"></i>Cambiar Contraseña
						</a>
						<a href="{{ route('logout') }}" class="nav-dropdown-item">
							<i class="fas fa-sign-out-alt mr-2"></i>Cerrar Sesión
						</a>
					</div>
				</div>
			</div>
		</div>
	</div>
</nav>

<!-- Secondary Navigation -->
<nav class="nav-menu-secondary">
	<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
		<div class="flex space-x-1">
			@foreach ($menuItems as $item)
				<div class="nav-menu-item">
					<button type="button" class="nav-menu-button" onclick="toggleConfigMenu('{{ $loop->index }}')">
						<i class="{{ $item['icon'] }} nav-menu-icon"></i>
						{{ $item['name'] }}
						<i class="fas fa-chevron-down ml-2 text-xs"></i>
					</button>
					
					<div id="configDropdown{{ $loop->index }}" class="hidden nav-submenu">
						@foreach ($item['submenu'] as $subitem)
							<a href="{{ isset($subitem['params']) ? route($subitem['route'], $subitem['params']) : route($subitem['route']) }}" class="nav-submenu-item {{ request()->routeIs($subitem['route']) ? 'active' : '' }}">
								<i class="fas {{ $subitem['icon'] }} mr-2"></i>{{ $subitem['name'] }}
							</a>
						@endforeach
					</div>
				</div>
			@endforeach
		</div>
	</div>
</nav>

@push('scripts')
<script>
	// Función para mostrar/ocultar el menú de usuario
	function toggleDropdown() {
		const dropdown = document.getElementById('userDropdown');
		
		// Cerrar todos los menús de configuración primero
		closeAllConfigMenus();
		
		// Toggle del dropdown de usuario
		if (dropdown.classList.contains('hidden')) {
			dropdown.classList.remove('hidden');
		} else {
			dropdown.classList.add('hidden');
		}
	}
	
	// Función para mostrar/ocultar los submenús de configuración
	function toggleConfigMenu(index) {
		const dropdown = document.getElementById('configDropdown' + index);
		
		// Cerrar el dropdown de usuario
		document.getElementById('userDropdown').classList.add('hidden');
		
		// Cerrar todos los demás menús de configuración
		const allDropdowns = document.querySelectorAll('[id^="configDropdown"]');
		allDropdowns.forEach(function(menu) {
			if (menu.id !== 'configDropdown' + index) {
				menu.classList.add('hidden');
			}
		});
		
		// Toggle del dropdown actual
		if (dropdown.classList.contains('hidden')) {
			dropdown.classList.remove('hidden');
		} else {
			dropdown.classList.add('hidden');
		}
	}
	
	// Función para cerrar todos los menús de configuración
	function closeAllConfigMenus() {
		const configDropdowns = document.querySelectorAll('[id^="configDropdown"]');
		configDropdowns.forEach(function(dropdown) {
			dropdown.classList.add('hidden');
		});
	}

	// Cerrar menús al hacer clic fuera de ellos
	document.addEventListener('click', function(event) {
		// Verificar si el clic fue dentro de algún menú o botón
		const isInsideMenu = event.target.closest('.nav-dropdown, .nav-submenu, button[onclick^="toggleConfigMenu"], button[onclick="toggleDropdown()"]');
		
		// Si el clic fue fuera de los menús y botones, cerrar todos los menús
		if (!isInsideMenu) {
			closeAllConfigMenus();
			document.getElementById('userDropdown').classList.add('hidden');
		}
	});
</script>
@endpush
