/**
 * Script para la gestión de funciones
 */
import { showAlert } from '../common/alerts.js';
import { getCsrfToken } from '../common/utils.js';
import { openModal as openModalBase, closeModal as closeModalBase, setupModalOutsideClick } from '../common/modals.js';

// Variables globales
let editingFuncionId = null;

/**
 * Inicializa los eventos y funcionalidades de la página
 */
export function init() {
	// Configurar cierre de modales al hacer clic fuera
	setupModalOutsideClick();
	
	// Asegurar que el modal esté oculto inicialmente
	const modal = document.getElementById('funcionModal');
	if (modal) {
		modal.style.display = 'none';
	}
	
	// Agregar data-id a las filas para facilitar la actualización de estado
	document.querySelectorAll('tbody tr').forEach(tr => {
		const id = tr.querySelector('td:first-child')?.textContent.trim();
		if (id && !isNaN(id)) {
			tr.setAttribute('data-id', id);
		}
	});
	
	// Configurar formulario
	setupForm();
}

/**
 * Configura el formulario de funciones
 */
function setupForm() {
	const form = document.getElementById('funcionForm');
	if (form) {
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			
			const formData = new FormData(form);
			const method = editingFuncionId ? 'PUT' : 'POST';
			const url = editingFuncionId ? `/funciones/${editingFuncionId}` : '/funciones';
			
			// Agregar estado como booleano
			const estado = document.getElementById('estado').checked;
			formData.set('estado', estado ? '1' : '0');
			
			fetch(url, {
				method: method,
				headers: {
					'X-CSRF-TOKEN': getCsrfToken()
				},
				body: formData
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					window.location.reload();
				} else {
					showAlert(data.message || 'Error al guardar la función', 'danger');
				}
			})
			.catch(error => {
				console.error('Error:', error);
				showAlert('Error al guardar la función', 'danger');
			});
		});
	}
}

/**
 * Abre el modal para crear una nueva función
 */
export function openCreateModal() {
	editingFuncionId = null;
	document.getElementById('modalTitle').textContent = 'Nueva Función';
	document.getElementById('funcionForm').action = '/funciones';
	document.getElementById('methodField').innerHTML = '';
	document.getElementById('funcionForm').reset();
	document.getElementById('estado').checked = true;
	
	openModalBase('funcionModal');
}

/**
 * Abre el modal para editar una función existente
 * @param {number} id - ID de la función a editar
 */
export function editFuncion(id) {
	editingFuncionId = id;
	
	fetch(`/funciones/${id}`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const funcion = data.data;
				
				document.getElementById('modalTitle').textContent = 'Editar Función';
				document.getElementById('funcionForm').action = `/funciones/${id}`;
				document.getElementById('methodField').innerHTML = '@method("PUT")';
				
				document.getElementById('nombre').value = funcion.nombre;
				document.getElementById('descripcion').value = funcion.descripcion || '';
				document.getElementById('estado').checked = funcion.estado;
				
				openModalBase('funcionModal');
			} else {
				showAlert('Error al cargar los datos de la función', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cargar los datos de la función', 'danger');
		});
}

/**
 * Cierra el modal de funciones
 */
export function closeModal() {
	closeModalBase('funcionModal');
}

/**
 * Elimina una función
 * @param {number} id - ID de la función a eliminar
 */
export function deleteFuncion(id) {
	if (confirm('¿Estás seguro de que deseas eliminar esta función?')) {
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = `/funciones/${id}`;
		form.innerHTML = `
			<input type="hidden" name="_token" value="${getCsrfToken()}">
			<input type="hidden" name="_method" value="DELETE">
		`;
		document.body.appendChild(form);
		form.submit();
	}
}

/**
 * Cambia el estado de una función (activo/inactivo)
 * @param {number} id - ID de la función
 * @param {boolean} status - Nuevo estado
 * @param {string} type - Tipo de entidad ('funcion')
 */
export function toggleStatus(id, status, type) {
	fetch(`/${type}s/${id}/toggle-status`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-CSRF-TOKEN': getCsrfToken()
		},
		body: JSON.stringify({ estado: status })
	})
	.then(response => response.json())
	.then(data => {
		if (!data.success) {
			location.reload();
		} else {
			const statusLabel = document.querySelector(`tr[data-id="${id}"] .toggle-label`);
			if (statusLabel) {
				statusLabel.textContent = status ? 'Activo' : 'Inactivo';
				statusLabel.className = `toggle-label ${status ? 'active' : 'inactive'}`;
			}
		}
	})
	.catch(error => {
		console.error('Error:', error);
		showAlert('Error al cambiar el estado de la función', 'danger');
	});
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', init);
