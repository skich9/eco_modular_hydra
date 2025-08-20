@extends('layouts.app')

@section('title', 'Cambiar Contraseña - Sistema de Cobros CETA')

@section('content')
<div class="app-container">
	<!-- Navigation Header -->
	<nav class="navbar">
		<div class="navbar-container">
			<div class="navbar-wrapper">
				<div class="navbar-brand">
					<a href="{{ route('dashboard') }}" class="navbar-logo">
						<img src="{{ asset('images/logo-ceta.png') }}" alt="Logo CETA" class="logo-image">
						<span class="logo-text">Sistema de Cobros CETA</span>
					</a>
				</div>
				
				<div class="navbar-actions">
					<a href="{{ route('dashboard') }}" class="btn-link">
						<i class="fas fa-arrow-left mr-2"></i>Volver al Dashboard
					</a>
				</div>
			</div>
		</div>
	</nav>

	<!-- Main Content -->
	<div class="content-container">
		<div class="card auth-card">
			<div class="card-header">
				<h3 class="card-title">
					<i class="fas fa-key mr-2"></i>Cambiar Contraseña
				</h3>
			</div>
			<div class="card-body">

				<!-- Alerts -->
				@if($errors->any())
					<div class="alert alert-danger mb-4 alert-auto-hide">
						<div class="alert-content">
							<i class="fas fa-exclamation-triangle mr-2"></i>
							<span>{{ $errors->first() }}</span>
						</div>
					</div>
				@endif

				@if(session('success'))
					<div class="alert alert-success mb-4 alert-auto-hide">
						<div class="alert-content">
							<i class="fas fa-check-circle mr-2"></i>
							<span>{{ session('success') }}</span>
						</div>
					</div>
				@endif

				<form action="{{ route('change-password.post') }}" method="POST" class="form-container">
					@csrf

					<!-- Current Password -->
					<div class="form-group">
						<label for="current_password" class="form-label">
							Contraseña Actual
						</label>
						<div class="input-group">
							<input 
								id="current_password" 
								name="current_password" 
								type="password" 
								required 
								class="form-input" 
								placeholder="Ingrese su contraseña actual"
							>
							<button 
								type="button" 
								class="password-toggle"
								onclick="togglePassword('current_password', 'current-password-icon')"
							>
								<i id="current-password-icon" class="fas fa-eye"></i>
							</button>
						</div>
					</div>

					<!-- New Password -->
					<div class="form-group">
						<label for="new_password" class="form-label">
							Nueva Contraseña
						</label>
						<div class="input-group">
							<input 
								id="new_password" 
								name="new_password" 
								type="password" 
								required 
								class="form-input" 
								placeholder="Mínimo 6 caracteres"
							>
							<button 
								type="button" 
								class="password-toggle"
								onclick="togglePassword('new_password', 'new-password-icon')"
							>
								<i id="new-password-icon" class="fas fa-eye"></i>
							</button>
						</div>
					</div>

					<!-- Confirm New Password -->
					<div class="form-group">
						<label for="new_password_confirmation" class="form-label">
							Confirmar Nueva Contraseña
						</label>
						<div class="input-group">
							<input 
								id="new_password_confirmation" 
								name="new_password_confirmation" 
								type="password" 
								required 
								class="form-input" 
								placeholder="Confirme su nueva contraseña"
							>
							<button 
								type="button" 
								class="password-toggle"
								onclick="togglePassword('new_password_confirmation', 'confirm-password-icon')"
							>
								<i id="confirm-password-icon" class="fas fa-eye"></i>
							</button>
						</div>
					</div>

					<!-- Submit Button -->
					<div class="form-actions">
						<button type="submit" class="btn-primary">
							<i class="fas fa-save mr-2"></i>Guardar Cambios
						</button>
						<a 
							href="{{ route('dashboard') }}" 
							class="btn-link"
						>
							Cancelar
						</a>
					</div>
				</form>
			</div>
		</div>

		<!-- Password Requirements -->
		<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
			<h4 class="text-sm font-medium text-blue-800 mb-2">Requisitos de la contraseña:</h4>
			<ul class="text-sm text-blue-700 space-y-1">
				<li><i class="fas fa-check mr-2"></i>Mínimo 6 caracteres</li>
				<li><i class="fas fa-check mr-2"></i>Se recomienda incluir números y símbolos</li>
				<li><i class="fas fa-check mr-2"></i>Evite usar información personal</li>
			</ul>
		</div>
	</div>
</div>
@endsection
