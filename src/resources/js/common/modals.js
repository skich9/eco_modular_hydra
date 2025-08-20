/**
 * Funciones comunes para manejar modales en la aplicación
 */

/**
 * Abre un modal por su ID
 * @param {string} modalId - ID del modal a abrir
 */
export function openModal(modalId) {
	const modal = document.getElementById(modalId);
	if (modal) {
		modal.classList.add('show');
		modal.style.display = 'block';
	}
}

/**
 * Cierra un modal por su ID
 * @param {string} modalId - ID del modal a cerrar
 */
export function closeModal(modalId) {
	const modal = document.getElementById(modalId);
	if (modal) {
		modal.classList.remove('show');
		modal.style.display = 'none';
	}
}

/**
 * Configura los eventos para cerrar modales al hacer clic fuera de ellos
 */
export function setupModalOutsideClick() {
	window.onclick = function(event) {
		const modals = document.getElementsByClassName('modal');
		for (let i = 0; i < modals.length; i++) {
			if (event.target == modals[i]) {
				modals[i].style.display = 'none';
				modals[i].classList.remove('show');
			}
		}
	};
}

/**
 * Configura un botón para abrir un modal
 * @param {string} buttonId - ID del botón
 * @param {string} modalId - ID del modal a abrir
 */
export function setupModalButton(buttonId, modalId) {
	const button = document.getElementById(buttonId);
	if (button) {
		button.addEventListener('click', () => openModal(modalId));
	}
}
