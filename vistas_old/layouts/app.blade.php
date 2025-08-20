<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	<meta name="csrf-token" content="{{ csrf_token() }}">
	<title>@yield('title', 'Sistema de Cobros - Instituto Tecnológico CETA')</title>
	
	<!-- Bootstrap CSS -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	
	<!-- Tailwind CSS -->
	<script src="https://cdn.tailwindcss.com"></script>
	
	<!-- Font Awesome -->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
	
	<!-- Google Fonts - Inter -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	
	<!-- Vite Assets -->
	@vite(['resources/css/app.css', 'resources/js/app.js'])
	
	<!-- Global CSS -->
	<link rel="stylesheet" href="{{ asset('css/styles.css') }}">
	
	<!-- Custom Styles específicos de la app que complementan el CSS global -->
	<style>
		.bg-gradient-ceta {
			background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 50%, var(--primary-light) 100%);
		}
		
		.text-shadow {
			text-shadow: 0 2px 4px rgba(0,0,0,0.3);
		}
	</style>
	
	@stack('styles')
</head>
<body class="min-h-screen">
	<!-- Menú de navegación -->
	@if(session()->has('usuario'))
		<x-navigation-menu :usuario="$usuario" />
	@endif
	
	<!-- Contenido principal -->
    <main class="{{ session()->has('usuario') ? 'pt-0' : '' }}">
        @yield('content')
    </main>
	<!-- Scripts -->
	<script>
		// Toggle password visibility
		function togglePassword(inputId, iconId) {
			const input = document.getElementById(inputId);
			const icon = document.getElementById(iconId);
			
			if (input.type === 'password') {
				input.type = 'text';
				icon.classList.remove('fa-eye');
				icon.classList.add('fa-eye-slash');
			} else {
				input.type = 'password';
				icon.classList.remove('fa-eye-slash');
				icon.classList.add('fa-eye');
			}
		}
		
		// Auto-hide alerts after 5 seconds
		setTimeout(() => {
			const alerts = document.querySelectorAll('.alert-auto-hide');
			alerts.forEach(alert => {
				alert.style.transition = 'opacity 0.5s ease';
				alert.style.opacity = '0';
				setTimeout(() => alert.remove(), 500);
			});
		}, 5000);
	</script>
	
	<!-- jQuery -->
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	
	<!-- Bootstrap JS -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	
	@stack('scripts')
</body>
</html>
