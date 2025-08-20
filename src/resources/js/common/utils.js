/**
 * Utilidades comunes para todas las vistas
 */

/**
 * Toggle password visibility
 * @param {string} inputId - ID del campo de contraseña
 * @param {string} iconId - ID del icono para cambiar
 */
export function togglePassword(inputId, iconId) {
	const input = document.getElementById(inputId);
	const icon = document.getElementById(iconId);
	
	if (input.type === 'password') {
		input.type = 'text';
		icon.classList.remove('fa-eye');
		icon.classList.add('fa-eye-slash');
	} else {
		input.type = 'password';
		icon.classList.remove('fa-eye-slash');
		icon.classList.add('fa-eye');
	}
}

/**
 * Auto-hide alerts after specified time
 * @param {number} timeout - Tiempo en milisegundos
 */
export function setupAutoHideAlerts(timeout = 5000) {
	setTimeout(() => {
		const alerts = document.querySelectorAll('.alert-auto-hide');
		alerts.forEach(alert => {
			alert.style.transition = 'opacity 0.5s ease';
			alert.style.opacity = '0';
			setTimeout(() => alert.remove(), 500);
		});
	}, timeout);
}

/**
 * Obtiene el token CSRF del meta tag
 * @returns {string} Token CSRF
 */
export function getCsrfToken() {
	const token = document.querySelector('meta[name="csrf-token"]');
	if (!token) {
		console.error('CSRF token not found');
		throw new Error('CSRF token not found');
	}
	return token.getAttribute('content');
}
