/**
 * Funcionalidad para la página de detalles de carrera
 */

// Configuración de pestañas
function setupTabs() {
	const tabButtons = document.querySelectorAll('.tab-button');
	const tabContents = document.querySelectorAll('.tab-content');
	
	tabButtons.forEach((button, index) => {
		button.addEventListener('click', (e) => {
			e.preventDefault();
			const tabId = button.getAttribute('data-tab');
			
			// Remover clase active de todos
			tabButtons.forEach(btn => btn.classList.remove('active'));
			tabContents.forEach(content => content.classList.remove('active'));
			
			// Activar el seleccionado
			button.classList.add('active');
			document.getElementById('tab-' + tabId).classList.add('active');
		});
	});
}

// Configuración de búsqueda en tablas
function setupSearch() {
	// Configurar búsqueda para cada tabla
	const searchConfigs = [
		{ inputId: 'searchPensumsInput', tableId: 'pensumsTable' },
		{ inputId: 'searchMateriasInput', tableId: 'materiasTable' }
	];
	
	searchConfigs.forEach(function(config) {
		const input = document.getElementById(config.inputId);
		const table = document.getElementById(config.tableId);
		
		if (input && table) {
			input.addEventListener('keyup', function() {
				const filter = input.value.toUpperCase();
				const rows = table.getElementsByTagName('tr');
				
				for (let i = 1; i < rows.length; i++) {
					let found = false;
					const cells = rows[i].getElementsByTagName('td');
					
					for (let j = 0; j < cells.length; j++) {
						const cell = cells[j];
						if (cell && cell.textContent.toUpperCase().indexOf(filter) > -1) {
							found = true;
							break;
						}
					}
					
					rows[i].style.display = found ? '' : 'none';
				}
			});
		}
	});
}

// Carga de pensums
function loadPensums(codigoCarrera) {
	fetch(`/api/carreras/${codigoCarrera}/pensums`)
		.then(response => response.json())
		.then(data => {
			const pensumsTable = document.getElementById('pensumsTable').getElementsByTagName('tbody')[0];
			pensumsTable.innerHTML = '';
			
			if (data.length === 0) {
				pensumsTable.innerHTML = '<tr><td colspan="4" class="text-center">No hay pensums disponibles</td></tr>';
				return;
			}
			
			data.forEach(pensum => {
				const row = document.createElement('tr');
				row.innerHTML = `
					<td>${pensum.cod_pensum}</td>
					<td>${pensum.descripcion}</td>
					<td>${pensum.fecha_ini}</td>
					<td>
						<button type="button" class="btn btn-sm btn-primary view-pensum" 
							data-cod-pensum="${pensum.cod_pensum}">
							<i class="fas fa-eye"></i> Ver Materias
						</button>
					</td>
				`;
				pensumsTable.appendChild(row);
			});
			
			// Configurar botones para ver materias
			document.querySelectorAll('.view-pensum').forEach(button => {
				button.addEventListener('click', function() {
					const codPensum = this.getAttribute('data-cod-pensum');
					loadMaterias(codPensum);
					
					// Cambiar a la pestaña de materias
					document.querySelector('[data-tab="materias"]').click();
					
					// Actualizar título de pestaña con el pensum seleccionado
					document.getElementById('materiasTabTitle').innerText = `Materias - Pensum: ${codPensum}`;
					document.getElementById('selectedPensum').value = codPensum;
				});
			});
		})
		.catch(error => {
			console.error('Error cargando pensums:', error);
			document.getElementById('pensumsTable').getElementsByTagName('tbody')[0].innerHTML = 
				'<tr><td colspan="4" class="text-center">Error cargando pensums</td></tr>';
		});
}

// Carga de materias
function loadMaterias(codPensum) {
	fetch(`/api/pensums/${codPensum}/materias`)
		.then(response => response.json())
		.then(data => {
			const materiasTable = document.getElementById('materiasTable').getElementsByTagName('tbody')[0];
			materiasTable.innerHTML = '';
			
			if (data.length === 0) {
				materiasTable.innerHTML = '<tr><td colspan="8" class="text-center">No hay materias disponibles</td></tr>';
				return;
			}
			
			data.forEach(materia => {
				const row = document.createElement('tr');
				row.innerHTML = `
					<td>${materia.sigla}</td>
					<td>${materia.descripcion}</td>
					<td>${materia.creditos}</td>
					<td>${materia.horas_teoricas}</td>
					<td>${materia.horas_practicas}</td>
					<td>${materia.nivel}</td>
					<td>${materia.estado ? 'Activo' : 'Inactivo'}</td>
					<td>
						<button type="button" class="btn btn-sm btn-warning edit-materia" 
							data-sigla="${materia.sigla}" 
							data-cod-pensum="${codPensum}"
							data-bs-toggle="modal" 
							data-bs-target="#materiaModal">
							<i class="fas fa-edit"></i>
						</button>
						<button type="button" class="btn btn-sm btn-danger delete-materia"
							data-sigla="${materia.sigla}" 
							data-cod-pensum="${codPensum}"
							data-bs-toggle="modal" 
							data-bs-target="#confirmDeleteModal">
							<i class="fas fa-trash-alt"></i>
						</button>
					</td>
				`;
				materiasTable.appendChild(row);
			});
			
			// Configurar botones de edición
			setupEditButtons();
			
			// Configurar botones de eliminación
			setupDeleteButtons();
		})
		.catch(error => {
			console.error('Error cargando materias:', error);
			document.getElementById('materiasTable').getElementsByTagName('tbody')[0].innerHTML = 
				'<tr><td colspan="8" class="text-center">Error cargando materias</td></tr>';
		});
}

// Configurar botones de edición de materias
function setupEditButtons() {
	document.querySelectorAll('.edit-materia').forEach(button => {
		button.addEventListener('click', function() {
			const sigla = this.getAttribute('data-sigla');
			const codPensum = this.getAttribute('data-cod-pensum');
			
			// Obtener datos de la materia
			fetch(`/api/materias/${sigla}/${codPensum}`)
				.then(response => response.json())
				.then(materia => {
					// Llenar el formulario con los datos
					document.getElementById('materiaForm').reset();
					document.getElementById('materiaForm').setAttribute('data-mode', 'edit');
					document.getElementById('materiaModalLabel').innerText = 'Editar Materia';
					
					document.getElementById('sigla').value = materia.sigla;
					document.getElementById('siglaOriginal').value = materia.sigla;
					document.getElementById('descripcion').value = materia.descripcion;
					document.getElementById('creditos').value = materia.creditos;
					document.getElementById('horas_teoricas').value = materia.horas_teoricas;
					document.getElementById('horas_practicas').value = materia.horas_practicas;
					document.getElementById('nivel').value = materia.nivel;
					document.getElementById('estado').checked = materia.estado;
					document.getElementById('cod_pensum').value = materia.cod_pensum;
				})
				.catch(error => {
					console.error('Error obteniendo datos de materia:', error);
					alert('Error al cargar los datos de la materia');
				});
		});
	});
}

// Configurar botones de eliminación
function setupDeleteButtons() {
	document.querySelectorAll('.delete-materia').forEach(button => {
		button.addEventListener('click', function() {
			const sigla = this.getAttribute('data-sigla');
			const codPensum = this.getAttribute('data-cod-pensum');
			
			document.getElementById('deleteSigla').value = sigla;
			document.getElementById('deletePensum').value = codPensum;
		});
	});
}

// Guardar materia (crear o actualizar)
function saveMateria(event) {
	event.preventDefault();
	
	const form = document.getElementById('materiaForm');
	const formData = new FormData(form);
	const mode = form.getAttribute('data-mode');
	
	// URL y método según modo (crear o editar)
	let url = '/api/materias';
	let method = 'POST';
	
	if (mode === 'edit') {
		const siglaOriginal = document.getElementById('siglaOriginal').value;
		const codPensum = document.getElementById('cod_pensum').value;
		url = `/api/materias/${siglaOriginal}/${codPensum}`;
		method = 'PUT';
	}
	
	// Convertir estado de checkbox a booleano
	formData.set('estado', formData.get('estado') === 'on');
	
	// Convertir FormData a JSON
	const data = Object.fromEntries(formData.entries());
	
	// Enviar petición
	fetch(url, {
		method: method,
		headers: {
			'Content-Type': 'application/json',
			'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
		},
		body: JSON.stringify(data)
	})
		.then(response => {
			if (!response.ok) {
				return response.json().then(err => {
					throw new Error(err.message || 'Error al guardar la materia');
				});
			}
			return response.json();
		})
		.then(data => {
			// Cerrar modal
			bootstrap.Modal.getInstance(document.getElementById('materiaModal')).hide();
			
			// Recargar materias
			loadMaterias(document.getElementById('selectedPensum').value);
			
			// Mostrar mensaje de éxito
			alert(data.message || 'Materia guardada exitosamente');
		})
		.catch(error => {
			console.error('Error guardando materia:', error);
			alert(error.message || 'Error al guardar la materia');
		});
}

// Confirmar eliminación de materia
function confirmDelete() {
	const sigla = document.getElementById('deleteSigla').value;
	const codPensum = document.getElementById('deletePensum').value;
	
	fetch(`/api/materias/${sigla}/${codPensum}`, {
		method: 'DELETE',
		headers: {
			'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
		}
	})
		.then(response => {
			if (!response.ok) {
				return response.json().then(err => {
					throw new Error(err.message || 'Error al eliminar la materia');
				});
			}
			return response.json();
		})
		.then(data => {
			// Cerrar modal
			bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();
			
			// Recargar materias
			loadMaterias(document.getElementById('selectedPensum').value);
			
			// Mostrar mensaje de éxito
			alert(data.message || 'Materia eliminada exitosamente');
		})
		.catch(error => {
			console.error('Error eliminando materia:', error);
			alert(error.message || 'Error al eliminar la materia');
		});
}

// Inicializar nueva materia
function initNewMateria() {
	document.getElementById('materiaForm').reset();
	document.getElementById('materiaForm').setAttribute('data-mode', 'create');
	document.getElementById('materiaModalLabel').innerText = 'Nueva Materia';
	document.getElementById('cod_pensum').value = document.getElementById('selectedPensum').value;
}

// Inicializar componentes al cargar la página
document.addEventListener('DOMContentLoaded', function() {
	// Configurar pestañas y búsqueda
	setupTabs();
	setupSearch();
	
	// Obtener código de carrera desde el elemento oculto
	const codigoCarrera = document.getElementById('codigoCarrera').value;
	
	// Cargar pensums iniciales
	loadPensums(codigoCarrera);
	
	// Configurar formulario de materias
	document.getElementById('materiaForm').addEventListener('submit', saveMateria);
	
	// Configurar botón de nueva materia
	document.getElementById('btnNewMateria').addEventListener('click', initNewMateria);
	
	// Configurar botón de confirmación de eliminación
	document.getElementById('btnConfirmDelete').addEventListener('click', confirmDelete);
});
