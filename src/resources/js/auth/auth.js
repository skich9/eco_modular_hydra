/**
 * Script para la gestión de autenticación
 */

/**
 * Alterna la visibilidad de la contraseña en el formulario de login
 */
export function togglePasswordVisibility() {
	const passwordInput = document.getElementById('password');
	const toggleButton = document.querySelector('.login-password-toggle i');
	
	if (passwordInput.type === 'password') {
		passwordInput.type = 'text';
		toggleButton.classList.remove('fa-eye');
		toggleButton.classList.add('fa-eye-slash');
	} else {
		passwordInput.type = 'password';
		toggleButton.classList.remove('fa-eye-slash');
		toggleButton.classList.add('fa-eye');
	}
}

/**
 * Inicializa los eventos y funcionalidades de la página de autenticación
 */
export function init() {
	// Configurar validación del formulario si es necesario
	const loginForm = document.querySelector('.login-form');
	if (loginForm) {
		loginForm.addEventListener('submit', function(e) {
			// Aquí se puede agregar validación adicional si es necesario
		});
	}
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', init);
