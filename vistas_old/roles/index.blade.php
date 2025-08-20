@extends('layouts.app')

@section('title', 'Gestión de Roles')

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />

<div class="content-container">
	<div class="content-wrapper">
		<!-- Header -->
		<div class="card mb-4">
			<div class="card-header">
				<div class="header-wrapper">
					<div class="header-content">
						<h1 class="page-title">Gestión de Roles</h1>
						<p class="page-subtitle">Administra los roles del sistema</p>
					</div>
					<button onclick="openModal()" class="btn-primary">
						<i class="fas fa-plus mr-2"></i>
						Nuevo Rol
					</button>
				</div>
			</div>
		</div>

		<!-- Alerts -->
		@if(session('success'))
			<div class="alert alert-success mb-4">
				{{ session('success') }}
			</div>
		@endif

		@if(session('error'))
			<div class="alert alert-danger mb-4">
				{{ session('error') }}
			</div>
		@endif

		<!-- Tabla de Roles -->
		<div class="card">
			<div class="table-container">
				<table class="table">
					<thead>
						<tr>
							<th>Nro</th>
							<th>Nombre</th>
							<th>Descripción</th>
							<th>Usuarios</th>
							<th>Estado</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
						@forelse($roles as $rol)
							<tr>
								<td>{{ $rol->id_rol }}</td>
								<td>
									<div class="font-medium">{{ $rol->nombre }}</div>
								</td>
								<td>
									{{ $rol->descripcion ?? 'Sin descripción' }}
								</td>
								<td>
									<span class="badge badge-info">
										{{ $rol->usuarios_count }} usuarios
									</span>
								</td>
								<td>
									<label class="toggle-switch">
										<input type="checkbox" 
											   class="toggle-input"
											   {{ $rol->estado ? 'checked' : '' }}
											   onchange="toggleStatus({{ $rol->id_rol }}, this.checked, 'rol')">
										<span class="toggle-label {{ $rol->estado ? 'active' : 'inactive' }}">
											{{ $rol->estado ? 'Activo' : 'Inactivo' }}
										</span>
									</label>
								</td>
								<td class="actions-cell">
									<button onclick="editRol({{ $rol->id_rol }})" class="btn-secondary btn-sm">
										<i class="fas fa-edit"></i> Editar
									</button>
									<button onclick="deleteRol({{ $rol->id_rol }})" class="btn-danger btn-sm">
										<i class="fas fa-trash"></i> Eliminar
									</button>
								</td>
							</tr>
						@empty
							<tr>
								<td colspan="6" class="empty-table">
									No hay roles registrados
								</td>
							</tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<!-- Modal para Crear/Editar Rol -->
<div id="rolModal" class="modal">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h3 class="modal-title" id="modalTitle">Nuevo Rol</h3>
				<button type="button" onclick="closeModal()" class="close">
					<i class="fas fa-times"></i>
				</button>
			</div>
			
			<div class="modal-body">
				<form id="rolForm" method="POST">
					@csrf
					<div id="methodField"></div>
					
					<div class="form-group mb-3">
						<label for="nombre" class="form-label">Nombre *</label>
						<input type="text" id="nombre" name="nombre" required class="form-input">
					</div>
					
					<div class="form-group mb-3">
						<label for="descripcion" class="form-label">Descripción</label>
						<textarea id="descripcion" name="descripcion" rows="3" class="form-textarea"></textarea>
					</div>
					
					<div class="form-group mb-4">
						<div class="form-check">
							<input type="checkbox" id="estado" name="estado" value="1" checked class="form-check-input">
							<label for="estado" class="form-check-label">Activo</label>
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

@section('scripts')
@vite('resources/js/configuracion/roles.js')
@endsection
