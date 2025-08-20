/**
 * Script para la gestión de roles
 */
import { showAlert } from '../common/alerts.js';
import { getCsrfToken } from '../common/utils.js';
import { openModal as openModalBase, closeModal as closeModalBase, setupModalOutsideClick } from '../common/modals.js';

// Variables globales
let editingRolId = null;

/**
 * Inicializa los eventos y funcionalidades de la página
 */
export function init() {
	// Configurar cierre de modales al hacer clic fuera
	setupModalOutsideClick();
	
	// Asegurar que el modal esté oculto inicialmente
	const modal = document.getElementById('rolModal');
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
 * Configura el formulario de roles
 */
function setupForm() {
	const form = document.getElementById('rolForm');
	if (form) {
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			
			const formData = new FormData(form);
			const method = editingRolId ? 'PUT' : 'POST';
			const url = editingRolId ? `/roles/${editingRolId}` : '/roles';
			
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
					showAlert(data.message || 'Error al guardar el rol', 'danger');
				}
			})
			.catch(error => {
				console.error('Error:', error);
				showAlert('Error al guardar el rol', 'danger');
			});
		});
	}
}

/**
 * Abre el modal para crear un nuevo rol
 */
export function openCreateModal() {
	editingRolId = null;
	document.getElementById('modalTitle').textContent = 'Nuevo Rol';
	document.getElementById('rolForm').action = '/roles';
	document.getElementById('methodField').innerHTML = '';
	document.getElementById('rolForm').reset();
	document.getElementById('estado').checked = true;
	
	openModalBase('rolModal');
}

/**
 * Abre el modal para editar un rol existente
 * @param {number} id - ID del rol a editar
 */
export function editRol(id) {
	editingRolId = id;
	
	fetch(`/roles/${id}`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const rol = data.data;
				
				document.getElementById('modalTitle').textContent = 'Editar Rol';
				document.getElementById('rolForm').action = `/roles/${id}`;
				document.getElementById('methodField').innerHTML = '@method("PUT")';
				
				document.getElementById('nombre').value = rol.nombre;
				document.getElementById('descripcion').value = rol.descripcion || '';
				document.getElementById('estado').checked = rol.estado;
				
				openModalBase('rolModal');
			} else {
				showAlert('Error al cargar los datos del rol', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cargar los datos del rol', 'danger');
		});
}

/**
 * Cierra el modal de roles
 */
export function closeModal() {
	closeModalBase('rolModal');
}

/**
 * Elimina un rol
 * @param {number} id - ID del rol a eliminar
 */
export function deleteRol(id) {
	if (confirm('¿Estás seguro de que deseas eliminar este rol?')) {
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = `/roles/${id}`;
		form.innerHTML = `
			<input type="hidden" name="_token" value="${getCsrfToken()}">
			<input type="hidden" name="_method" value="DELETE">
		`;
		document.body.appendChild(form);
		form.submit();
	}
}

/**
 * Cambia el estado de un rol (activo/inactivo)
 * @param {number} id - ID del rol
 * @param {boolean} status - Nuevo estado
 * @param {string} type - Tipo de entidad ('rol')
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
		showAlert('Error al cambiar el estado del rol', 'danger');
	});
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', init);
