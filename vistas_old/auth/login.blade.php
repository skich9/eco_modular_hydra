@extends('layouts.app')

@section('title', 'Iniciar Sesión - Sistema de Cobros CETA')

@section('content')
<div class="login-container">
	<!-- Card Container -->
	<div class="login-card">
		<!-- Header -->
		<div class="login-header">
			<div class="login-logo">
				<img src="{{ asset('images/logo-ceta.png') }}" alt="Logo CETA" class="login-logo-image">
			</div>
			
			<h2 class="login-title">Sistema de Cobros</h2>
			<p class="login-subtitle">Instituto Tecnológico CETA</p>
		</div>

			<!-- Login Form -->
			<form class="login-form" action="{{ route('login.post') }}" method="POST">
				@csrf
				
				<!-- Errores -->
				@if ($errors->any())
					<div class="alert alert-danger">
						@foreach ($errors->all() as $error)
							<p>{{ $error }}</p>
						@endforeach
					</div>
				@endif

				<!-- Email/Usuario -->
				<div class="login-form-group">
					<label for="email" class="login-label">
						Usuario
					</label>
					<input 
						id="email" 
						name="email" 
						type="text" 
						required 
						class="login-input"
						placeholder="admin@ceta.edu.bo"
						value="{{ old('email') }}"
					>
				</div>

				<!-- Contraseña -->
				<div class="login-form-group">
					<label for="password" class="login-label">
						Contraseña
					</label>
					<div class="login-password-container">
						<input 
							id="password" 
							name="password" 
							type="password" 
							required 
							class="login-input"
							placeholder="********"
						>
						<button 
							type="button" 
							class="login-password-toggle" 
							onclick="togglePasswordVisibility()"
						>
							<i class="fas fa-eye"></i>
						</button>
					</div>
				</div>

				<!-- Botón de Iniciar Sesión -->
				<div>
					<button type="submit" class="login-button">
						Iniciar Sesión
					</button>
				</div>
			</form>

			<!-- Footer -->
			<div class="login-footer">
				<p>
					© {{ date('Y') }} Instituto Tecnológico CETA. Todos los derechos reservados.
				</p>
			</div>
		</div>
	</div>
</div>

@section('scripts')
@vite('resources/js/auth/auth.js')
@endsection
