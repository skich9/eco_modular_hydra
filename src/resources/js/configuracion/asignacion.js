/**
 * Script para la gestión de asignaciones de costos específicas
 */
import { showAlert } from '../common/alerts.js';
import { getCsrfToken } from '../common/utils.js';
import { openModal, closeModal, setupModalOutsideClick } from '../common/modals.js';

// Variable global para almacenar los datos de la asignación a eliminar
let deleteAsignacionData = null;

/**
 * Inicializa los eventos y funcionalidades de la página
 */
export function init() {
	// Configurar cierre de modales al hacer clic fuera
	setupModalOutsideClick();
}

/**
 * Abre el modal para crear una nueva asignación
 * @param {number} costoSemestralId - ID del costo semestral
 */
export function openAsignacionModal(costoSemestralId) {
	document.getElementById('costoSemestralId').value = costoSemestralId;
	
	// Cargar inscripciones disponibles
	const pensumCodigo = document.getElementById('pensumCodigo').value;
	fetch(`/api/inscripciones/pensum/${pensumCodigo}`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const inscripcionSelect = document.getElementById('inscripcionId');
				inscripcionSelect.innerHTML = '<option value="">Seleccione una inscripción</option>';
				
				data.data.forEach(inscripcion => {
					const option = document.createElement('option');
					option.value = inscripcion.cod_inscrip;
					option.textContent = inscripcion.nombre || `Inscripción #${inscripcion.cod_inscrip}`;
					inscripcionSelect.appendChild(option);
				});
				
				openModal('asignacionModal');
			} else {
				showAlert('Error al cargar las inscripciones', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cargar las inscripciones', 'danger');
		});
}

/**
 * Cierra el modal de asignación
 */
export function closeAsignacionModal() {
	closeModal('asignacionModal');
	document.getElementById('asignacionForm').reset();
}

/**
 * Abre el modal para editar una asignación existente
 * @param {string} codPensum - Código del pensum
 * @param {string} codInscrip - Código de inscripción
 * @param {number} idAsignacion - ID de la asignación
 */
export function openEditAsignacionModal(codPensum, codInscrip, idAsignacion) {
	document.getElementById('editPensumCodigo').value = codPensum;
	document.getElementById('editInscripcionId').value = codInscrip;
	document.getElementById('editAsignacionId').value = idAsignacion;
	
	// Cargar datos de la asignación
	fetch(`/configuracion/asignacion-economica/${codPensum}/${codInscrip}/${idAsignacion}/show`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const asignacion = data.data;
				document.getElementById('editMonto').value = asignacion.monto;
				document.getElementById('editObservaciones').value = asignacion.observaciones || '';
				
				openModal('editAsignacionModal');
			} else {
				showAlert('Error al cargar los datos de la asignación de costo', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cargar los datos de la asignación de costo', 'danger');
		});
}

/**
 * Cierra el modal de edición de asignación
 */
export function closeEditAsignacionModal() {
	closeModal('editAsignacionModal');
	document.getElementById('editAsignacionForm').reset();
}

/**
 * Abre el modal de confirmación para eliminar una asignación
 * @param {string} codPensum - Código del pensum
 * @param {string} codInscrip - Código de inscripción
 * @param {number} idAsignacion - ID de la asignación
 */
export function confirmDeleteAsignacion(codPensum, codInscrip, idAsignacion) {
	deleteAsignacionData = { codPensum, codInscrip, idAsignacion };
	openModal('deleteAsignacionModal');
}

/**
 * Cierra el modal de confirmación para eliminar
 */
export function closeDeleteAsignacionModal() {
	closeModal('deleteAsignacionModal');
	deleteAsignacionData = null;
}

/**
 * Elimina una asignación de costo
 * @param {string} codPensum - Código del pensum
 * @param {string} codInscrip - Código de inscripción
 * @param {number} idAsignacion - ID de la asignación
 */
export function deleteAsignacion(codPensum, codInscrip, idAsignacion) {
	try {
		const token = getCsrfToken();
		
		fetch(`/configuracion/asignacion-economica/${codPensum}/${codInscrip}/${idAsignacion}`, {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			}
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				window.location.reload();
			} else {
				showAlert(`Error: ${data.message}`, 'danger');
				closeDeleteAsignacionModal();
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al eliminar la asignación de costo', 'danger');
			closeDeleteAsignacionModal();
		});
	} catch (error) {
		showAlert(error.message, 'danger');
		closeDeleteAsignacionModal();
	}
}

/**
 * Cambia el estado de una asignación (activo/inactivo)
 * @param {string} codPensum - Código del pensum
 * @param {string} codInscrip - Código de inscripción
 * @param {number} idAsignacion - ID de la asignación
 */
export function toggleAsignacionStatus(codPensum, codInscrip, idAsignacion) {
	try {
		const token = getCsrfToken();
		
		fetch(`/configuracion/asignacion-economica/${codPensum}/${codInscrip}/${idAsignacion}/toggle-status`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			}
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				window.location.reload();
			} else {
				showAlert('Error al cambiar el estado de la asignación de costo', 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al cambiar el estado de la asignación de costo', 'danger');
		});
	} catch (error) {
		showAlert(error.message, 'danger');
	}
}

/**
 * Guarda una nueva asignación de costo
 */
export function saveAsignacion() {
	const formData = {
		cod_pensum: document.getElementById('pensumCodigo').value,
		cod_inscrip: document.getElementById('inscripcionId').value,
		monto: document.getElementById('monto').value,
		observaciones: document.getElementById('observaciones').value,
		estado: true,
		id_costo_semestral: document.getElementById('costoSemestralId').value
	};
	
	if (!formData.cod_inscrip || !formData.monto) {
		showAlert('Por favor complete todos los campos obligatorios', 'warning');
		return;
	}
	
	try {
		const token = getCsrfToken();
		
		fetch('/configuracion/asignacion-economica/asignacion', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			},
			body: JSON.stringify(formData)
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				window.location.reload();
			} else {
				const errorMessages = data.errors ? Object.values(data.errors).flat().join('\n') : data.message;
				showAlert(`Error: ${errorMessages}`, 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al crear la asignación de costo', 'danger');
		});
	} catch (error) {
		showAlert(error.message, 'danger');
	}
}

/**
 * Actualiza una asignación de costo existente
 */
export function updateAsignacion() {
	const codPensum = document.getElementById('editPensumCodigo').value;
	const codInscrip = document.getElementById('editInscripcionId').value;
	const idAsignacion = document.getElementById('editAsignacionId').value;
	
	const formData = {
		monto: document.getElementById('editMonto').value,
		observaciones: document.getElementById('editObservaciones').value,
		estado: true
	};
	
	if (!formData.monto) {
		showAlert('Por favor complete todos los campos obligatorios', 'warning');
		return;
	}
	
	try {
		const token = getCsrfToken();
		
		fetch(`/configuracion/asignacion-economica/${codPensum}/${codInscrip}/${idAsignacion}`, {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			},
			body: JSON.stringify(formData)
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				window.location.reload();
			} else {
				const errorMessages = data.errors ? Object.values(data.errors).flat().join('\n') : data.message;
				showAlert(`Error: ${errorMessages}`, 'danger');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showAlert('Error al actualizar la asignación de costo', 'danger');
		});
	} catch (error) {
		showAlert(error.message, 'danger');
	}
}

/**
 * Configura el botón de eliminar asignación
 */
export function setupDeleteButton() {
	const deleteButton = document.getElementById('confirmDeleteAsignacionBtn');
	if (deleteButton) {
		deleteButton.addEventListener('click', function() {
			if (deleteAsignacionData) {
				const { codPensum, codInscrip, idAsignacion } = deleteAsignacionData;
				deleteAsignacion(codPensum, codInscrip, idAsignacion);
			}
		});
	}
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
	init();
	setupDeleteButton();
});
