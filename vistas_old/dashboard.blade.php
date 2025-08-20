@extends('layouts.app')

@section('title', 'Dashboard - Sistema de Cobros CETA')

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />

<div class="content-container p-4">
	<!-- Main Content -->
	<div class="content-wrapper">
		<!-- Welcome Message -->
		@if(session('success'))
			<div class="alert alert-success mb-4 alert-auto-hide">
				<div class="alert-content">
					<i class="fas fa-check-circle mr-2"></i>
					<span>{{ session('success') }}</span>
				</div>
			</div>
		@endif

		<!-- Page Header -->
		<div class="card mb-4">
			<div class="card-header">
				<div class="header-wrapper">
					<div class="header-content">
						<h1 class="text-2xl font-bold text-primary">Dashboard</h1>
						<p class="text-secondary">Bienvenido al Sistema de Cobros del Instituto Tecnológico CETA</p>
					</div>
				</div>
			</div>
		</div>

		<!-- Stats Cards -->
		<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
			<div class="card shadow">
				<div class="flex items-center">
					<div class="p-3 rounded-full bg-primary-light mr-4">
						<i class="fas fa-users text-primary text-xl"></i>
					</div>
					<div>
						<div class="text-sm text-secondary">Total Usuarios</div>
						<div class="text-xl font-bold">{{ $usuarioCompleto->count() ?? 0 }}</div>
					</div>
				</div>
			</div>

			<div class="card shadow">
				<div class="flex items-center">
					<div class="p-3 rounded-full bg-success-color bg-opacity-20 mr-4">
						<i class="fas fa-user-tag text-success text-xl"></i>
					</div>
					<div>
						<div class="text-sm text-secondary">Mi Rol</div>
						<div class="text-xl font-bold">{{ $usuario['rol_nombre'] }}</div>
					</div>
				</div>
			</div>

			<div class="card shadow">
				<div class="flex items-center">
					<div class="p-3 rounded-full bg-warning-color bg-opacity-20 mr-4">
						<i class="fas fa-tasks text-warning text-xl"></i>
					</div>
					<div>
						<div class="text-sm text-secondary">Mis Funciones</div>
						<div class="text-xl font-bold">{{ $usuarioCompleto->funciones->count() ?? 0 }}</div>
					</div>
				</div>
			</div>

			<div class="card shadow">
				<div class="flex items-center">
					<div class="p-3 rounded-full bg-info-color bg-opacity-20 mr-4">
						<i class="fas fa-clock text-info text-xl"></i>
					</div>
					<div>
						<div class="text-sm text-secondary">Último Acceso</div>
						<div class="text-xl font-bold">{{ now()->format('H:i') }}</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Quick Actions -->
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
			<!-- User Info Card -->
			<div class="card shadow">
				<div class="card-header">
					<h3 class="text-lg font-bold text-primary">Mi Perfil</h3>
				</div>
				<div class="p-4">
					<h3 class="text-lg font-medium mb-4">
						<i class="fas fa-user mr-2 text-primary"></i>Información Personal
					</h3>
					<div class="space-y-3">
						<div class="flex justify-between">
							<span class="text-sm font-medium text-secondary">Nombre:</span>
							<span class="text-sm">{{ $usuario['nombre_completo'] }}</span>
						</div>
						<div class="flex justify-between">
							<span class="text-sm font-medium text-secondary">Usuario:</span>
							<span class="text-sm">{{ $usuario['nickname'] }}</span>
						</div>
						<div class="flex justify-between">
							<span class="text-sm font-medium text-secondary">CI:</span>
							<span class="text-sm">{{ $usuario['ci'] }}</span>
						</div>
						<div class="flex justify-between">
							<span class="text-sm font-medium text-secondary">Rol:</span>
							<span class="text-sm">{{ $usuario['rol_nombre'] }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Functions Card -->
			<div class="card shadow">
				<div class="card-header">
					<h3 class="text-lg font-bold text-primary">
						<i class="fas fa-tasks mr-2"></i>Mis Funciones Asignadas
					</h3>
				</div>
				<div class="p-4">
					@if($usuarioCompleto->funciones && $usuarioCompleto->funciones->count() > 0)
						<div class="space-y-2">
							@foreach($usuarioCompleto->funciones as $funcion)
								<div class="flex items-center justify-between p-2 bg-light-color rounded">
									<span class="text-sm font-medium">{{ $funcion->nombre }}</span>
									<span class="text-xs {{ $funcion->pivot->fecha_fin ? 'text-danger' : 'text-success' }}">
										@if($funcion->pivot->fecha_fin)
											Finalizada
										@else
											Activa
										@endif
									</span>
								</div>
							@endforeach
						</div>
					@else
						<p class="text-sm text-secondary">No tienes funciones asignadas actualmente.</p>
					@endif
				</div>
			</div>
		</div>

		<!-- Quick Access Menu -->
		<div class="mt-8">
			<h3 class="text-xl font-bold text-primary mb-4">Acceso Rápido</h3>
			<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
				<a href="{{ route('usuarios.index') }}" class="card shadow p-4 text-center hover:shadow-lg transition-shadow duration-200">
					<i class="fas fa-users text-2xl text-primary mb-2"></i>
					<p class="text-sm font-medium">Usuarios</p>
				</a>
				<a href="{{ route('roles.index') }}" class="card shadow p-4 text-center hover:shadow-lg transition-shadow duration-200">
					<i class="fas fa-user-tag text-2xl text-success mb-2"></i>
					<p class="text-sm font-medium">Roles</p>
				</a>
				<a href="{{ route('funciones.index') }}" class="card shadow p-4 text-center hover:shadow-lg transition-shadow duration-200">
					<i class="fas fa-tasks text-2xl text-warning mb-2"></i>
					<p class="text-sm font-medium">Funciones</p>
				</a>
				<a href="#" class="card shadow p-4 text-center hover:shadow-lg transition-shadow duration-200">
					<i class="fas fa-chart-bar text-2xl text-info mb-2"></i>
					<p class="text-sm font-medium">Reportes</p>
				</a>
			</div>
		</div>
	</div>
</div>


@endsection
