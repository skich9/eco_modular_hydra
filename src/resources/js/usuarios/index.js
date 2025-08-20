/**
 * Script para la gestión de usuarios
 */
import { showNotification } from '../common/alerts.js';
import { getCsrfToken } from '../common/utils.js';
import { openModal, closeModal, setupModalOutsideClick } from '../common/modals.js';

// Variables globales
let isEditing = false;
let editingUserId = null;

/**
 * Inicializa los eventos y funcionalidades de la página
 */
export function init() {
	// Configurar cierre de modales al hacer clic fuera
	setupModalOutsideClick();
	
	// Configurar eventos de búsqueda
	setupSearch();
}

/**
 * Configura la funcionalidad de búsqueda
 */
function setupSearch() {
	const searchInput = document.getElementById('searchInput');
	if (searchInput) {
		searchInput.addEventListener('keyup', function() {
			const searchTerm = this.value.toLowerCase();
			const rows = document.querySelectorAll('#usersTableBody tr');
			
			rows.forEach(row => {
				const nickname = row.cells[0]?.textContent.toLowerCase() || '';
				const nombre = row.cells[1]?.textContent.toLowerCase() || '';
				const rol = row.cells[2]?.textContent.toLowerCase() || '';
				
				if (nickname.includes(searchTerm) || nombre.includes(searchTerm) || rol.includes(searchTerm)) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		});
	}
}

/**
 * Abre el modal para crear un nuevo usuario
 */
export function openCreateModal() {
	isEditing = false;
	document.getElementById('modalTitle').textContent = 'Crear Usuario';
	document.getElementById('userForm').action = '/usuarios';
	document.getElementById('methodField').innerHTML = '';
	document.getElementById('passwordField').style.display = 'block';
	document.getElementById('contrasenia').required = true;
	
	// Limpiar formulario
	document.getElementById('userForm').reset();
	document.getElementById('estado').checked = true;
	
	openModal('userModal');
}

/**
 * Abre el modal para editar un usuario existente
 * @param {number} userId - ID del usuario a editar
 */
export function editUser(userId) {
	isEditing = true;
	editingUserId = userId;
	document.getElementById('modalTitle').textContent = 'Editar Usuario';
	document.getElementById('userForm').action = `/usuarios/${userId}`;
	document.getElementById('methodField').innerHTML = '@method("PUT")';
	document.getElementById('passwordField').style.display = 'none';
	document.getElementById('contrasenia').required = false;
	
	// Cargar los datos del usuario via AJAX
	fetch(`/usuarios/${userId}/edit`)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const usuario = data.data;
				document.getElementById('nickname').value = usuario.nickname;
				document.getElementById('nombre').value = usuario.nombre;
				document.getElementById('ap_paterno').value = usuario.ap_paterno;
				document.getElementById('ap_materno').value = usuario.ap_materno || '';
				document.getElementById('ci').value = usuario.ci;
				document.getElementById('id_rol').value = usuario.id_rol;
				document.getElementById('estado').checked = usuario.estado;
				
				openModal('userModal');
			} else {
				showNotification('Error al cargar los datos del usuario', 'error');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showNotification('Error al cargar los datos del usuario', 'error');
		});
}

/**
 * Cierra el modal de usuarios
 */
export function closeModal() {
	const modal = document.getElementById('userModal');
	modal.classList.remove('show');
	modal.style.display = 'none';
}

/**
 * Cambia el estado de un usuario (activo/inactivo)
 * @param {number} userId - ID del usuario
 * @param {boolean} status - Nuevo estado
 */
export function toggleUserStatus(userId, status) {
	try {
		const token = getCsrfToken();
		
		fetch(`/usuarios/${userId}/estado`, {
			method: 'PATCH',
			headers: {
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN': token
			},
			body: JSON.stringify({ estado: status })
		})
		.then(response => response.json())
		.then(data => {
			if (!data.success) {
				showNotification('Error al cambiar el estado del usuario', 'error');
				// Revertir checkbox
				event.target.checked = !status;
			} else {
				// Actualizar la etiqueta de estado
				const statusLabel = document.querySelector(`tr[data-id="${userId}"] .toggle-label`);
				if (statusLabel) {
					statusLabel.textContent = status ? 'Activo' : 'Inactivo';
					statusLabel.className = `toggle-label ${status ? 'active' : 'inactive'}`;
				}
				showNotification('Estado actualizado correctamente', 'success');
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showNotification('Error al cambiar el estado del usuario', 'error');
			event.target.checked = !status;
		});
	} catch (error) {
		showNotification(error.message, 'error');
		event.target.checked = !status;
	}
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', init);
