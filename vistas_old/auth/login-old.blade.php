@extends('layouts.app')

@section('title', 'Iniciar Sesión - Sistema de Cobros CETA')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-400 via-blue-500 to-blue-600 py-12 px-4 sm:px-6 lg:px-8">
	<div class="max-w-md w-full space-y-8">
		<!-- Card Container -->
		<div class="bg-white rounded-2xl shadow-2xl p-8 space-y-8">
			<!-- Header -->
			<div class="text-center">
				<!-- Logo CETA -->
				<div class="mx-auto h-24 w-24 bg-white rounded-2xl flex items-center justify-center mb-6 card-shadow">
					<img src="{{ asset('images/logo-ceta.png') }}" alt="Logo CETA" class="h-16 w-16 object-contain">
				</div>
		<div class="bg-white/10 backdrop-blur-lg rounded-2xl p-8 card-shadow">
			<form class="space-y-6" action="{{ route('login.post') }}" method="POST">
				@csrf
				
				<!-- Alerts -->
				@if($errors->any())
					<div class="bg-red-500/20 border border-red-400 text-red-100 px-4 py-3 rounded-lg alert-auto-hide">
						<div class="flex items-center">
							<i class="fas fa-exclamation-triangle mr-2"></i>
							<span>{{ $errors->first() }}</span>
						</div>
					</div>
				@endif

				@if(session('success'))
					<div class="bg-green-500/20 border border-green-400 text-green-100 px-4 py-3 rounded-lg alert-auto-hide">
						<div class="flex items-center">
							<i class="fas fa-check-circle mr-2"></i>
							<span>{{ session('success') }}</span>
						</div>
					</div>
				@endif

				<!-- Usuario Field -->
				<div>
					<label for="email" class="block text-sm font-medium text-white mb-2">
						Usuario
					</label>
					<div class="relative">
						<input 
							id="email" 
							name="email" 
							type="text" 
							required 
							class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/60 input-focus focus:outline-none focus:ring-2 focus:ring-blue-400 transition-all duration-200" 
							placeholder="Ingrese su usuario o CI"
							value="{{ old('email') }}"
						>
						<div class="absolute inset-y-0 right-0 pr-3 flex items-center">
							<i class="fas fa-user text-white/60"></i>
						</div>
					</div>
				</div>

				<!-- Password Field -->
				<div>
					<label for="password" class="block text-sm font-medium text-white mb-2">
						Contraseña
					</label>
					<div class="relative">
						<input 
							id="password" 
							name="password" 
							type="password" 
							required 
							class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/60 input-focus focus:outline-none focus:ring-2 focus:ring-blue-400 transition-all duration-200" 
							placeholder="••••••••"
						>
						<button 
							type="button" 
							class="absolute inset-y-0 right-0 pr-3 flex items-center"
							onclick="togglePassword('password', 'password-icon')"
						>
							<i id="password-icon" class="fas fa-eye text-white/60 hover:text-white transition-colors duration-200"></i>
						</button>
					</div>
				</div>

				<!-- Submit Button -->
				<div>
					<button 
						type="submit" 
						class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg text-white font-semibold btn-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
					>
						<span class="flex items-center">
							<i class="fas fa-sign-in-alt mr-2"></i>
							Iniciar Sesión
						</span>
					</button>
				</div>
			</form>
		</div>

		<!-- Footer Features -->
		<div class="grid grid-cols-2 gap-6 text-center">
			<div class="text-white/80">
				<div class="bg-white/10 rounded-lg p-4 mb-2">
					<i class="fas fa-shield-alt text-2xl"></i>
				</div>
				<p class="text-sm">Seguro</p>
			</div>
			<div class="text-white/80">
				<div class="bg-white/10 rounded-lg p-4 mb-2">
					<i class="fas fa-chart-line text-2xl"></i>
				</div>
				<p class="text-sm">Eficiente</p>
			</div>
		</div>
	</div>
</div>
@endsection
