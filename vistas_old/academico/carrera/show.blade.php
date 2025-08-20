@extends('layouts.app')

@section('title', 'Carrera - ' . $carrera->nombre)

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />
<div class="container-fluid py-4">
	<div class="row">
		<div class="col-12">
			<div class="card">
				<div class="card-header">
					<h1 class="card-title">{{ $carrera->nombre }}</h1>
					<p class="text-muted">{{ $carrera->descripcion }}</p>
					<div class="d-flex align-items-center">
						<span class="badge {{ $carrera->estado ? 'bg-success' : 'bg-danger' }} me-2">
							{{ $carrera->estado ? 'Activo' : 'Inactivo' }}
						</span>
						<span class="text-muted">Código: {{ $carrera->codigo_carrera }}</span>
					</div>
				</div>
				
				<div class="card-body">
					<!-- Sección de información general -->
					<div class="info-section mb-4">
						<div class="row">
							<div class="col-md-6">
								<div class="info-card">
									<div class="info-card-icon">
										<i class="fas fa-book"></i>
									</div>
									<div class="info-card-content">
										<h3>Pensums</h3>
										<p>Planes de estudio disponibles para la carrera de {{ $carrera->nombre }}.</p>
									</div>
								</div>
							</div>
							<div class="col-md-6">
								<div class="info-card">
									<div class="info-card-icon">
										<i class="fas fa-graduation-cap"></i>
									</div>
									<div class="info-card-content">
										<h3>Materias</h3>
										<p>Asignaturas académicas con sus respectivos costos y créditos.</p>
									</div>
								</div>
							</div>
						</div>
					</div>
					
					<!-- Navegación por pestañas -->
					<div class="tab-navigation">
						<a href="#pensums" class="tab-button active" data-tab="pensums">
							<i class="fas fa-book"></i> Pensums
						</a>
						<a href="#materias" class="tab-button" data-tab="materias">
							<i class="fas fa-graduation-cap"></i> Materias
						</a>
					</div>
					
					<!-- Contenido de pestañas -->
					<!-- Pestaña: Pensums -->
					<div class="tab-content active" id="tab-pensums">
						<div class="d-flex justify-content-between align-items-center mb-3">
							<div class="search-container">
								<input type="text" id="searchPensumsInput" class="search-input" placeholder="Buscar pensums...">
								<i class="fas fa-search search-icon"></i>
							</div>
						</div>
						
						<div class="table-responsive">
							<table id="pensumsTable" class="table table-striped table-hover">
								<thead>
									<tr>
										<th>Código</th>
										<th>Nombre</th>
										<th>Descripción</th>
										<th>Estado</th>
										<th>Acciones</th>
									</tr>
								</thead>
								<tbody>
									<!-- Los datos se cargarán dinámicamente con JavaScript -->
								</tbody>
							</table>
						</div>
					</div>
					
					<!-- Pestaña: Materias -->
					<div class="tab-content" id="tab-materias">
						<div class="d-flex justify-content-between align-items-center mb-3">
							<div class="search-container">
								<input type="text" id="searchMateriasInput" class="search-input" placeholder="Buscar materias...">
								<i class="fas fa-search search-icon"></i>
							</div>
							<div>
								<select id="selectPensum" class="form-select">
									<option value="">Seleccione un pensum...</option>
									@foreach($pensums as $pensum)
										<option value="{{ $pensum->cod_pensum }}">{{ $pensum->nombre_pensum }}</option>
									@endforeach
								</select>
							</div>
							<button id="btnNuevaMateria" class="btn btn-primary" data-action="create" data-type="materia">
								<i class="fas fa-plus"></i> Nueva Materia
							</button>
						</div>
						
						<div class="table-responsive">
							<table id="materiasTable" class="table table-striped table-hover">
								<thead>
									<tr>
										<th>Sigla</th>
										<th>Pensum</th>
										<th>Nombre</th>
										<th>Nombre Oficial</th>
										<th>Créditos</th>
										<th>Orden</th>
										<th>Parámetro Económico</th>
										<th>Estado</th>
										<th>Acciones</th>
									</tr>
								</thead>
								<tbody>
									<!-- Los datos se cargarán dinámicamente con JavaScript -->
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

<!-- Modal para crear/editar materia -->
<div class="modal fade" id="materiaModal" tabindex="-1" aria-labelledby="materiaModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="materiaModalLabel">Nueva Materia</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form id="materiaForm" class="needs-validation" novalidate>
					<div class="mb-3">
						<label for="materiaSigla">Sigla</label>
						<input type="text" class="form-control" id="materiaSigla" name="sigla_materia" required>
					</div>
					<div class="mb-3">
						<label for="materiaPensum">Pensum</label>
						<select class="form-control" id="materiaPensum" name="cod_pensum" required>
							@foreach($pensums as $pensum)
								<option value="{{ $pensum->cod_pensum }}">{{ $pensum->cod_pensum }} - {{ $pensum->nombre_pensum }}</option>
							@endforeach
						</select>
					</div>
					<div class="mb-3">
						<label for="materiaNombre">Nombre</label>
						<input type="text" class="form-control" id="materiaNombre" name="nombre_materia" required>
					</div>
					<div class="mb-3">
						<label for="materiaNombreOficial">Nombre Material Oficial</label>
						<input type="text" class="form-control" id="materiaNombreOficial" name="nombre_material_oficial" required>
					</div>
					<div class="mb-3">
						<label for="materiaCreditos">Número de Créditos</label>
						<input type="number" class="form-control" id="materiaCreditos" name="nro_creditos" required>
					</div>
					<div class="mb-3">
						<label for="materiaOrden">Orden</label>
						<input type="number" class="form-control" id="materiaOrden" name="orden" required min="1">
					</div>
					<div class="mb-3">
						<label for="materiaParametroEconomico">Parámetro Económico</label>
						<select class="form-control" id="materiaParametroEconomico" name="id_parametro_economico" required>
							<option value="">Seleccione un parámetro</option>
						</select>
					</div>
					<div class="mb-3">
						<label for="materiaDescripcion">Descripción</label>
						<textarea class="form-control" id="materiaDescripcion" name="descripcion" rows="3" maxlength="50"></textarea>
					</div>
					<div class="mb-3">
						<label for="materiaEstado">Estado</label>
						<select class="form-control" id="materiaEstado" name="estado">
							<option value="1">Activo</option>
							<option value="0">Inactivo</option>
						</select>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
				<button type="button" class="btn btn-primary" id="btnGuardarMateria">Guardar</button>
			</div>
		</div>
	</div>
</div>

<!-- Modal de confirmación para eliminar -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="deleteModalLabel">Confirmar Eliminación</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p>¿Está seguro que desea eliminar este registro? Esta acción no se puede deshacer.</p>
				<input type="hidden" id="deleteId">
				<input type="hidden" id="deleteType">
				<input type="hidden" id="deleteSigla">
				<input type="hidden" id="deletePensum">
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
				<button type="button" class="btn btn-danger" id="btnConfirmDelete">Eliminar</button>
			</div>
		</div>
	</div>
</div>
</div>

@push('scripts')
@vite(['resources/js/academico/carrera/show.js'])
@endpush

<!-- Elemento oculto para pasar datos del servidor a JavaScript -->
<input type="hidden" id="codigoCarrera" value="{{ $carrera->codigo_carrera }}">
<input type="hidden" id="selectedPensum" value="">
@endsection
