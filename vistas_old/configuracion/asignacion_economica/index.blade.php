@extends('layouts.app')

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />

<div class="container">
	<div class="page-header">
		<h1>Asignación Económica</h1>
	</div>

	<!-- Alerta de éxito -->
	@if(session('success'))
	<div id="success-alert" class="alert alert-success" role="alert">
		<p>{{ session('success') }}</p>
		<button onclick="document.getElementById('success-alert').remove()" class="alert-close">
			<i class="fas fa-times"></i>
		</button>
	</div>
	@endif

	<!-- Alerta de error -->
	@if(session('error'))
	<div id="error-alert" class="alert alert-danger" role="alert">
		<p>{{ session('error') }}</p>
		<button onclick="document.getElementById('error-alert').remove()" class="alert-close">
			<i class="fas fa-times"></i>
		</button>
	</div>
	@endif

	<!-- Formulario de selección de pensum y gestión -->
	<div class="card">
		<div class="card-header">
			<h2>Seleccionar Pensum y Gestión</h2>
		</div>
		<div class="card-body">
			<form id="seleccionForm" class="form-grid">
				<div class="form-group">
					<label for="pensum" class="form-label">Pensum</label>
					<select id="pensum" name="pensum" class="form-input" required>
						<option value="">Seleccione un pensum</option>
						@foreach($pensums as $pensum)
							<option value="{{ $pensum->cod_pensum }}">{{ $pensum->nombre }}</option>
						@endforeach
					</select>
				</div>
				<div class="form-group">
					<label for="gestion" class="form-label">Gestión</label>
					<select id="gestion" name="gestion" class="form-input" required>
						<option value="">Seleccione una gestión</option>
						@for($i = date('Y') - 5; $i <= date('Y') + 1; $i++)
							<option value="{{ $i }}" {{ $i == $gestionActual ? 'selected' : '' }}>{{ $i }}</option>
						@endfor
					</select>
				</div>
				<div class="form-actions">
					<button type="submit" class="btn-primary">
						<i class="fas fa-search mr-2"></i> Buscar
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Sección para crear nuevo costo semestral -->
	<div class="card">
		<div class="card-header">
			<h2>Crear Nuevo Costo Semestral</h2>
		</div>
		<div class="card-body">
			<form id="costoSemestralForm" class="form-grid">
				<input type="hidden" id="usuario_id" name="id_usuario" value="{{ session('usuario_autenticado')['id'] }}">
				<div class="form-group">
					<label for="pensum_costo" class="form-label">Pensum</label>
					<select id="pensum_costo" name="cod_pensum" class="form-input" required>
						<option value="">Seleccione un pensum</option>
						@foreach($pensums as $pensum)
							<option value="{{ $pensum->cod_pensum }}">{{ $pensum->nombre }}</option>
						@endforeach
					</select>
				</div>
				<div class="form-group">
					<label for="gestion_costo" class="form-label">Gestión</label>
					<select id="gestion_costo" name="gestion" class="form-input" required>
						<option value="">Seleccione una gestión</option>
						@for($i = date('Y') - 5; $i <= date('Y') + 1; $i++)
							<option value="{{ $i }}" {{ $i == $gestionActual ? 'selected' : '' }}>{{ $i }}</option>
						@endfor
					</select>
				</div>
				<div class="form-group">
					<label for="semestre" class="form-label">Semestre</label>
					<select id="semestre" name="semestre" class="form-input" required>
						<option value="">Seleccione un semestre</option>
						<option value="1">Primer Semestre</option>
						<option value="2">Segundo Semestre</option>
					</select>
				</div>
				<div class="form-group full-width">
					<label for="monto_semestre" class="form-label">Monto Semestral</label>
					<input type="number" id="monto_semestre" name="monto_semestre" step="0.01" min="0" class="form-input" required>
				</div>
				<div class="form-actions">
					<button type="submit" class="btn-success">
						<i class="fas fa-plus mr-2"></i> Crear Costo Semestral
					</button>
			</div>
		</form>
	</div>

	<!-- Tabla de costos semestrales existentes -->
	<div class="card">
		<div class="card-header">
			<h2>Costos Semestrales Existentes</h2>
		</div>
		<div class="card-body">
			<div id="costosSemestralesContainer">
				<p class="text-center">Seleccione un pensum y una gestión para ver los costos semestrales</p>
			</div>
		</div>
	</div>
</div>

@endsection

@section('scripts')
@vite('resources/js/configuracion/asignacion-economica.js')
@endsection
