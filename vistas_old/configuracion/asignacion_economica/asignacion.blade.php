@extends('layouts.app')

@section('content')

<div class="content-container">
	<!-- Header -->
	<div class="card mb-6">
		<div class="card-header">
			<div class="flex justify-between items-center">
				<div>
					<h1 class="text-2xl font-bold">Asignación Económica</h1>
					<p class="text-sm text-secondary-color mt-1">Pensum: {{ $pensum->nombre }} | Gestión: {{ $gestion }}</p>
				</div>
				<a href="{{ route('asignacion_economica.index') }}" class="btn-secondary flex items-center">
					<i class="fas fa-arrow-left mr-2"></i> Volver
				</a>
			</div>
		</div>
	</div>

	<!-- Alertas -->
	@if(session('success'))
	<div id="success-alert" class="alert alert-success mb-4 alert-dismissible">
		<div class="alert-content">
			<i class="fas fa-check-circle mr-2"></i>
			<span>{{ session('success') }}</span>
		</div>
		<button type="button" class="close" onclick="document.getElementById('success-alert').remove()">
			<i class="fas fa-times"></i>
		</button>
	</div>
	@endif

	@if(session('error'))
	<div id="error-alert" class="alert alert-danger mb-4 alert-dismissible">
		<div class="alert-content">
			<i class="fas fa-exclamation-triangle mr-2"></i>
			<span>{{ session('error') }}</span>
		</div>
		<button type="button" class="close" onclick="document.getElementById('error-alert').remove()">
			<i class="fas fa-times"></i>
		</button>
	</div>
	@endif

	<!-- Costos Semestrales -->
	<div class="card mb-6">
		<div class="card-header">
			<h2 class="text-lg font-semibold">Costos Semestrales</h2>
		</div>
		<div class="card-body">
			@if($costosSemestrales->isEmpty())
				<div class="empty-data">
					<i class="fas fa-info-circle mr-1"></i> No hay costos semestrales definidos para este pensum y gestión
				</div>
			@else
				<div class="table-responsive">
					<table class="table table-hover">
						<thead>
							<tr>
								<th>ID</th>
								<th>Semestre</th>
								<th>Monto</th>
								<th>Fecha Creación</th>
								<th>Acciones</th>
							</tr>
						</thead>
						<tbody>
							@foreach($costosSemestrales as $costo)
							<tr data-id="{{ $costo->id_costo_semestral }}">
								<td>{{ $costo->id_costo_semestral }}</td>
								<td>{{ $costo->semestre == 1 ? 'Primer Semestre' : 'Segundo Semestre' }}</td>
								<td>{{ number_format($costo->monto_semestre, 2) }}</td>
								<td>{{ $costo->created_at->format('d/m/Y H:i') }}</td>
								<td>
									<button onclick="openAsignacionModal({{ $costo->id_costo_semestral }})" class="btn btn-sm btn-primary">
										<i class="fas fa-plus-circle mr-1"></i> Asignar Costo
									</button>
								</td>
							</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</div>
	</div>

	<!-- Asignaciones de Costos -->
	<div class="card">
		<div class="card-header">
			<h2 class="text-lg font-semibold">Asignaciones de Costos</h2>
		</div>
		<div class="card-body">
			@if($asignaciones->isEmpty())
				<div class="empty-data">
					<i class="fas fa-info-circle mr-1"></i> No hay asignaciones de costos para este pensum y gestión
				</div>
			@else
				<div class="table-responsive">
					<table class="table table-hover">
						<thead>
							<tr>
								<th>ID</th>
								<th>Inscripción</th>
								<th>Semestre</th>
								<th>Monto</th>
								<th>Observaciones</th>
								<th>Estado</th>
								<th>Acciones</th>
							</tr>
						</thead>
						<tbody>
							@foreach($asignaciones as $asignacion)
							<tr data-id="{{ $asignacion->id_asignacion_costo }}">
								<td>{{ $asignacion->id_asignacion_costo }}</td>
								<td>{{ $asignacion->inscripcion->nombre ?? 'N/A' }}</td>
								<td>{{ $asignacion->costoSemestral->semestre == 1 ? 'Primer Semestre' : 'Segundo Semestre' }}</td>
								<td>{{ number_format($asignacion->monto, 2) }}</td>
								<td>{{ $asignacion->observaciones ?? 'N/A' }}</td>
								<td>
									<span class="badge {{ $asignacion->estado ? 'badge-success' : 'badge-danger' }}">
										{{ $asignacion->estado ? 'Activo' : 'Inactivo' }}
									</span>
								</td>
								<td>
									<div class="btn-group">
										<button onclick="toggleAsignacionStatus('{{ $asignacion->cod_pensum }}', '{{ $asignacion->cod_inscrip }}', '{{ $asignacion->id_asignacion_costo }}')" class="btn btn-sm btn-icon {{ $asignacion->estado ? 'btn-light' : 'btn-secondary' }}" title="{{ $asignacion->estado ? 'Desactivar' : 'Activar' }}">
											<i class="fas {{ $asignacion->estado ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
										</button>
										<button onclick="openEditAsignacionModal('{{ $asignacion->cod_pensum }}', '{{ $asignacion->cod_inscrip }}', '{{ $asignacion->id_asignacion_costo }}')" class="btn btn-sm btn-icon btn-warning" title="Editar">
											<i class="fas fa-edit"></i>
										</button>
										<button onclick="confirmDeleteAsignacion('{{ $asignacion->cod_pensum }}', '{{ $asignacion->cod_inscrip }}', '{{ $asignacion->id_asignacion_costo }}')" class="btn btn-sm btn-icon btn-danger" title="Eliminar">
											<i class="fas fa-trash-alt"></i>
										</button>
									</div>
								</td>
							</tr>
							@endforeach
						</tbody>
					</table>
				</div>
			@endif
		</div>
	</div>

	<!-- Modal para asignar costo semestral -->
	<div id="asignacionModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">Asignar Costo Semestral</h3>
					<button type="button" class="close" onclick="closeAsignacionModal()">
						<i class="fas fa-times"></i>
					</button>
				</div>
				<div class="modal-body">
					<form id="asignacionForm">
						<input type="hidden" id="costoSemestralId" name="costoSemestralId">
						<input type="hidden" id="pensumCodigo" name="pensumCodigo" value="{{ $pensum->codigo }}">
						<input type="hidden" id="gestion" name="gestion" value="{{ $gestion }}">
						
						<div class="form-group mb-3">
							<label for="inscripcionId" class="form-label">Inscripción</label>
							<select id="inscripcionId" name="inscripcionId" class="form-select" required>
								<option value="">Seleccione una inscripción</option>
								@foreach($inscripciones as $inscripcion)
								<option value="{{ $inscripcion->cod_inscrip }}">{{ $inscripcion->nombre }}</option>
								@endforeach
							</select>
						</div>
						
						<div class="form-group mb-3">
							<label for="monto" class="form-label">Monto</label>
							<input type="number" step="0.01" id="monto" name="monto" class="form-control" required>
						</div>
						
						<div class="form-group mb-3">
							<label for="observaciones" class="form-label">Observaciones</label>
							<textarea id="observaciones" name="observaciones" rows="3" class="form-control"></textarea>
						</div>
					</form>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" onclick="closeAsignacionModal()">
						<i class="fas fa-times mr-1"></i> Cancelar
					</button>
					<button type="button" class="btn btn-primary" onclick="saveAsignacion()">
						<i class="fas fa-save mr-1"></i> Guardar
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Modal para editar asignación de costo -->
	<div id="editAsignacionModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">Editar Asignación de Costo</h3>
					<button type="button" class="close" onclick="closeEditAsignacionModal()">
						<i class="fas fa-times"></i>
					</button>
				</div>
				<div class="modal-body">
					<form id="editAsignacionForm">
						<input type="hidden" id="editPensumCodigo" name="pensumCodigo">
						<input type="hidden" id="editInscripcionId" name="inscripcionId">
						<input type="hidden" id="editAsignacionId" name="asignacionId">
						
						<div class="form-group mb-3">
							<label for="editMonto" class="form-label">Monto</label>
							<input type="number" step="0.01" id="editMonto" name="monto" class="form-control" required>
						</div>
						
						<div class="form-group mb-3">
							<label for="editObservaciones" class="form-label">Observaciones</label>
							<textarea id="editObservaciones" name="observaciones" rows="3" class="form-control"></textarea>
						</div>
					</form>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" onclick="closeEditAsignacionModal()">
						<i class="fas fa-times mr-1"></i> Cancelar
					</button>
					<button type="button" class="btn btn-warning" onclick="updateAsignacion()">
						<i class="fas fa-edit mr-1"></i> Actualizar
					</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Modal de confirmación para eliminar -->
	<div id="deleteAsignacionModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">Confirmar Eliminación</h3>
					<button type="button" class="close" onclick="closeDeleteAsignacionModal()">
						<i class="fas fa-times"></i>
					</button>
				</div>
				<div class="modal-body">
					<div class="alert alert-warning">
						<i class="fas fa-exclamation-triangle mr-2"></i>
						¿Está seguro que desea eliminar esta asignación de costo? Esta acción no se puede deshacer.
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" onclick="closeDeleteAsignacionModal()">
						<i class="fas fa-times mr-1"></i> Cancelar
					</button>
					<button type="button" id="confirmDeleteAsignacionBtn" class="btn btn-danger">
						<i class="fas fa-trash-alt mr-1"></i> Eliminar
					</button>
				</div>
			</div>
		</div>
	</div>
</div>

@endsection

@section('scripts')
<script type="module">
	import { openAsignacionModal, closeAsignacionModal, openEditAsignacionModal, closeEditAsignacionModal, confirmDeleteAsignacion, closeDeleteAsignacionModal, saveAsignacion, updateAsignacion, toggleAsignacionStatus, setupDeleteButton } from '{{ asset("js/configuracion/asignacion.js") }}';
	
	// Exponer funciones al ámbito global para uso en atributos HTML
	window.openAsignacionModal = openAsignacionModal;
	window.closeAsignacionModal = closeAsignacionModal;
	window.openEditAsignacionModal = openEditAsignacionModal;
	window.closeEditAsignacionModal = closeEditAsignacionModal;
	window.confirmDeleteAsignacion = confirmDeleteAsignacion;
	window.closeDeleteAsignacionModal = closeDeleteAsignacionModal;
	window.saveAsignacion = saveAsignacion;
	window.updateAsignacion = updateAsignacion;
	window.toggleAsignacionStatus = toggleAsignacionStatus;
	
	// Inicializar botones
	setupDeleteButton();
</script>
@endsection
