@extends('layouts.app')

@section('title', 'Registro de Usuarios - Sistema de Cobros CETA')

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />

	<!-- Main Content -->
	<div class="content-container">
		<!-- Page Header -->
		<div class="card mb-4">
			<div class="card-header">
				<div class="header-wrapper">
					<div class="header-content">
						<h1 class="page-title">Registro Usuarios</h1>
					</div>
				</div>
			</div>
		</div>

		<!-- Alerts -->
		@if(session('success'))
			<div class="alert alert-success mb-4 alert-auto-hide">
				<div class="alert-content">
					<i class="fas fa-check-circle mr-2"></i>
					<span>{{ session('success') }}</span>
				</div>
			</div>
		@endif

		@if(session('error'))
			<div class="alert alert-danger mb-4 alert-auto-hide">
				<div class="alert-content">
					<i class="fas fa-exclamation-triangle mr-2"></i>
					<span>{{ session('error') }}</span>
				</div>
			</div>
		@endif

		<!-- Users Table Card -->
		<div class="card">
			<!-- Table Header -->
			<div class="card-header">
				<h3 class="card-title">Lista de Usuarios</h3>
			</div>

			<!-- Controls -->
			<div class="filter-controls">
				<div class="filter-wrapper">
					<div class="filter-group">
						<div class="filter-item">
							<label class="filter-label">Mostrando</label>
							<select id="perPage" class="form-select form-select-sm">
								<option value="10">10</option>
								<option value="25">25</option>
								<option value="50">50</option>
							</select>
							<span class="filter-text">registros</span>
						</div>
					</div>
					
					<div class="filter-actions">
						<div class="search-box">
							<input 
								type="text" 
								id="searchInput"
								placeholder="Buscar..." 
								class="form-input"
							>
							<i class="fas fa-search search-icon"></i>
						</div>
						<button 
							onclick="openCreateModal()" 
							class="btn-primary"
						>
							<i class="fas fa-plus mr-2"></i>Añadir Usuario
						</button>
					</div>
				</div>
			</div>

			<!-- Table -->
			<div class="table-container">
				<table class="table">
					<thead>
						<tr>
							<th>
								<button class="sort-button">
									Usuario
									<i class="fas fa-sort ml-1"></i>
								</button>
							</th>
							<th>
								<button class="sort-button">
									Nombre
									<i class="fas fa-sort ml-1"></i>
								</button>
							</th>
							<th>
								<button class="sort-button">
									Rol
									<i class="fas fa-sort ml-1"></i>
								</button>
							</th>
                            <th>
								<button class="sort-button">
									Cargo
									<i class="fas fa-sort ml-1"></i>
								</button>
							</th>
							<th class="text-center">
								<button class="sort-button">
									Activo
									<i class="fas fa-sort ml-1"></i>
								</button>
							</th>
							<th class="text-center">
								Editar
							</th>
						</tr>
					</thead>
					<tbody id="usersTableBody">
						@forelse($usuarios as $usuario)
							<tr data-id="{{ $usuario->id_usuario }}">
								<td>
									{{ $usuario->nickname }}
								</td>
								<td>
									<div class="user-name">
										{{ $usuario->nombre }} {{ $usuario->ap_paterno }} {{ $usuario->ap_materno }}
									</div>
								</td>
								<td>
									{{ $usuario->rol->nombre ?? 'Sin rol' }}
								</td>
                                <td>
									{{ $usuario->rol->descripcion ?? 'Sin descripción' }}
								</td>
								<td class="text-center">
									<label class="toggle-switch">
										<input type="checkbox" 
											   class="toggle-input"
											   {{ $usuario->estado ? 'checked' : '' }}
											   onchange="toggleUserStatus({{ $usuario->id_usuario }}, this.checked)">
										<span class="toggle-label {{ $usuario->estado ? 'active' : 'inactive' }}">
											{{ $usuario->estado ? 'Activo' : 'Inactivo' }}
										</span>
									</label>
								</td>
								<td class="actions-cell">
									<button 
										onclick="editUser({{ $usuario->id_usuario }})"
										class="btn-secondary btn-sm"
									>
										<i class="fas fa-edit"></i> Editar
									</button>
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="6" class="empty-table">
									No hay usuarios registrados
								</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<div class="table-footer">
				<div class="table-info">
					<div class="pagination-info">
						Mostrando de 1 al {{ $usuarios->count() }} de {{ $usuarios->count() }} registros
					</div>
					<div class="flex space-x-1">
						<button class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">Primero</button>
						<button class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">Anterior</button>
						<button class="px-3 py-1 text-sm bg-blue-600 text-white border border-blue-600 rounded">1</button>
						<button class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">Siguiente</button>
						<button class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100">Último</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Modal para Crear/Editar Usuario -->
<div id="userModal" class="modal">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h3 class="modal-title" id="modalTitle">Crear Usuario</h3>
				<button type="button" onclick="closeModal()" class="close">
					<i class="fas fa-times"></i>
				</button>
			</div>
			
			<div class="modal-body">
				<form id="userForm" method="POST">
					@csrf
					<div id="methodField"></div>
					
					<div class="form-group mb-3">
						<label for="nickname" class="form-label">Usuario</label>
						<input type="text" name="nickname" id="nickname" required class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="nombre" class="form-label">Nombre</label>
						<input type="text" name="nombre" id="nombre" required class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="ap_paterno" class="form-label">Apellido Paterno</label>
						<input type="text" name="ap_paterno" id="ap_paterno" required class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="ap_materno" class="form-label">Apellido Materno</label>
						<input type="text" name="ap_materno" id="ap_materno" class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="ci" class="form-label">CI</label>
						<input type="text" name="ci" id="ci" required class="form-input">
					</div>
					
					<div id="passwordField" class="form-group mb-3">
						<label for="contrasenia" class="form-label">Contraseña</label>
						<input type="password" name="contrasenia" id="contrasenia" class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="id_rol" class="form-label">Rol</label>
						<select name="id_rol" id="id_rol" required class="form-select">
							<option value="">Seleccione un rol</option>
							@foreach($roles as $rol)
								<option value="{{ $rol->id_rol }}">{{ $rol->nombre }}</option>
							@endforeach
						</select>
					</div>
					
					<div class="form-group mb-4">
						<div class="form-check">
							<input type="checkbox" name="estado" id="estado" value="1" checked class="form-check-input">
							<label for="estado" class="form-check-label">Usuario Activo</label>
						</div>
					</div>
					
					<div class="modal-footer">
						<button type="button" onclick="closeModal()" class="btn btn-secondary">
							Cancelar
						</button>
						<button type="submit" class="btn btn-primary">
							Guardar
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

@push('scripts')
<script type="module">
	import { initUsuarios, openCreateModal, editUser, closeModal, toggleUserStatus } from '{{ asset("js/configuracion/usuarios.js") }}';
	
	// Exponer funciones al ámbito global para uso en atributos HTML
	window.openCreateModal = openCreateModal;
	window.editUser = editUser;
	window.closeModal = closeModal;
	window.toggleUserStatus = toggleUserStatus;
	
	// Inicializar módulo de usuarios cuando el DOM esté listo
	document.addEventListener('DOMContentLoaded', function() {
		initUsuarios();
	});
</script>
@endpush
@endsection
