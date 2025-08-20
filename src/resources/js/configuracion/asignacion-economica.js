/**
 * Script para la gestión de asignación económica
 */
import { showAlert } from '../common/alerts.js';
import { getCsrfToken } from '../common/utils.js';
import { openModal, closeModal, setupModalOutsideClick } from '../common/modals.js';

/**
 * Inicializa los eventos y funcionalidades de la página
 */
export function init() {
	// Configurar eventos para modales
	setupModalEvents();
	
	// Configurar cierre de modales al hacer clic fuera
	setupModalOutsideClick();
}

/**
 * Configura los eventos para los modales
 */
function setupModalEvents() {
	// Botones para cerrar modales
	const closeButtons = document.querySelectorAll('[data-close-modal]');
	closeButtons.forEach(button => {
		button.addEventListener('click', () => {
			const modalId = button.getAttribute('data-close-modal');
			closeModal(modalId);
		});
	});
}

/**
 * Abre el modal para crear un nuevo costo semestral
 */
export function openCreateModal() {
	document.getElementById('modal-title').textContent = 'Crear Costo Semestral';
	document.getElementById('costoForm').reset();
	document.getElementById('costo_id').value = '';
	openModal('costoModal');
}

/**
 * Abre el modal para editar un costo semestral existente
 * @param {number} id - ID del costo semestral a editar
 */
export function openEditModal(id) {
	document.getElementById('modal-title').textContent = 'Editar Costo Semestral';
	document.getElementById('costoForm').reset();
	document.getElementById('costo_id').value = id;
	
	// Obtener datos del costo semestral
	fetch(`/configuracion/asignacion-economica/${id}/show`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const costo = data.data;
				document.getElementById('cod_pensum').value = costo.cod_pensum;
				document.getElementById('gestion').value = costo.gestion;
				document.getElementById('semestre').value = costo.semestre;
				document.getElementById('costo_total').value = costo.costo_total;
				document.getElementById('descripcion').value = costo.descripcion || '';
				document.getElementById('estado').value = costo.estado ? '1' : '0';
				openModal('costoModal');
			} else {
				showAlert('Error al cargar los datos del costo semestral', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cargar los datos del costo semestral', 'danger');
		});
}

/**
 * Cierra el modal de costos semestrales
 */
export function closeCostoModal() {
	closeModal('costoModal');
}

/**
 * Abre el modal de confirmación para eliminar un costo semestral
 * @param {number} id - ID del costo semestral a eliminar
 */
export function confirmDelete(id) {
	window.deleteId = id;
	openModal('deleteModal');
}

/**
 * Cierra el modal de confirmación para eliminar
 */
export function closeDeleteModal() {
	closeModal('deleteModal');
	window.deleteId = null;
}

/**
 * Cambia el estado de un costo semestral (activo/inactivo)
 * @param {number} id - ID del costo semestral
 */
export function toggleStatus(id) {
	try {
		const token = getCsrfToken();
		
		fetch(`/configuracion/asignacion-economica/${id}/toggle-status`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			}
		})
		.then(response => {
			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`);
			}
			return response.json();
		})
		.then(data => {
			if (data.success) {
				window.location.reload();
			} else {
				showAlert('Error al cambiar el estado del costo semestral: ' + (data.message || 'Error desconocido'), 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cambiar el estado del costo semestral: ' + error.message, 'danger');
		});
	} catch (error) {
		showAlert(error.message, 'danger');
	}
}

/**
 * Configura el formulario de costos semestrales
 */
export function setupForm() {
	const form = document.getElementById('costoForm');
	if (form) {
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			
			try {
				const token = getCsrfToken();
				
				const id = document.getElementById('costo_id').value;
				const formData = {
					cod_pensum: document.getElementById('cod_pensum').value,
					gestion: document.getElementById('gestion').value,
					semestre: document.getElementById('semestre').value,
					costo_total: document.getElementById('costo_total').value,
					descripcion: document.getElementById('descripcion').value,
					estado: document.getElementById('estado').value === '1'
				};
				
				const url = id ? `/configuracion/asignacion-economica/${id}` : '/configuracion/asignacion-economica';
				const method = id ? 'PUT' : 'POST';
				
				fetch(url, {
					method: method,
					headers: {
						'Content-Type': 'application/json',
						'X-CSRF-TOKEN': token
					},
					body: JSON.stringify(formData)
				})
				.then(response => {
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`);
					}
					return response.json();
				})
				.then(data => {
					if (data.success) {
						window.location.reload();
					} else if (data.errors) {
						const errorMessages = Object.values(data.errors).flat().join('\n');
						showAlert(`Error: ${errorMessages}`, 'danger');
					} else {
						showAlert(`Error: ${data.message || 'Error desconocido'}`, 'danger');
					}
				})
				.catch(error => {
					console.error('Error:', error);
					showAlert('Error al guardar el costo semestral: ' + error.message, 'danger');
				});
			} catch (error) {
				showAlert(error.message, 'danger');
			}
		});
	}
}

/**
 * Configura el botón de eliminar costo semestral
 */
export function setupDeleteButton() {
	const deleteButton = document.getElementById('confirmDeleteBtn');
	if (deleteButton) {
		deleteButton.addEventListener('click', function() {
			if (window.deleteId) {
				try {
					const token = getCsrfToken();
					
					fetch(`/configuracion/asignacion-economica/${window.deleteId}`, {
						method: 'DELETE',
						headers: {
							'Content-Type': 'application/json',
							'X-CSRF-TOKEN': token
						}
					})
					.then(response => {
						if (!response.ok) {
							throw new Error(`HTTP error! status: ${response.status}`);
						}
						return response.json();
					})
					.then(data => {
						if (data.success) {
							window.location.reload();
						} else {
							showAlert(`Error: ${data.message || 'Error desconocido'}`, 'danger');
							closeDeleteModal();
						}
					})
					.catch(error => {
						console.error('Error:', error);
						showAlert('Error al eliminar el costo semestral: ' + error.message, 'danger');
						closeDeleteModal();
					});
				} catch (error) {
					showAlert(error.message, 'danger');
					closeDeleteModal();
				}
			}
		});
	}
}

/**
 * Redirige a la página de asignación de costos para un pensum específico
 * @param {string} codPensum - Código del pensum
 */
export function verAsignaciones(codPensum) {
	window.location.href = `/configuracion/asignacion-economica/${codPensum}`;
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
	init();
	setupForm();
	setupDeleteButton();
});
