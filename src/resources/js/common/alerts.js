/**
 * Funciones para mostrar alertas en la aplicación
 */

/**
 * Muestra una alerta en la parte superior de la página
 * @param {string} message - Mensaje a mostrar
 * @param {string} type - Tipo de alerta (success, danger, warning, info)
 * @param {number} timeout - Tiempo en milisegundos antes de que desaparezca (0 para no desaparecer)
 */
export function showAlert(message, type = 'success', timeout = 5000) {
	const alertDiv = document.createElement('div');
	const alertsDiv = document.createElement('div');
	alertsDiv.style.position = 'fixed';
	alertsDiv.style.top = '20px';
	alertsDiv.style.right = '20px';
	alertsDiv.style.zIndex = '9999';
	document.body.appendChild(alertsDiv);

	alertDiv.className = `alert alert-${type} alert-dismissible`;
	alertDiv.innerHTML = `
		<div class="alert-content">
			<i class="fas fa-${type === 'danger' ? 'exclamation-triangle' : type === 'warning' ? 'exclamation-circle' : 'check-circle'} mr-2"></i>
			<span>${message}</span>
		</div>
		<button type="button" class="close" onclick="this.parentElement.remove()">
			<i class="fas fa-times"></i>
		</button>
	`;
	alertsDiv.appendChild(alertDiv);

	if (timeout > 0) {
		setTimeout(() => {
			alertDiv.remove();
			if (alertsDiv.children.length === 0) {
				alertsDiv.remove();
			}
		}, timeout);
	}
}

/**
 * Muestra una notificación en la parte superior del contenedor principal
 * @param {string} message - Mensaje a mostrar
 * @param {string} type - Tipo de alerta (success, error)
 * @param {number} timeout - Tiempo en milisegundos antes de que desaparezca
 */
export function showNotification(message, type = 'success', timeout = 5000) {
	const alertDiv = document.createElement('div');
	alertDiv.className = `alert alert-${type === 'error' ? 'danger' : type} mb-4 alert-auto-hide`;
	
	const alertContent = document.createElement('div');
	alertContent.className = 'alert-content';
	
	const icon = document.createElement('i');
	icon.className = type === 'success' ? 'fas fa-check-circle mr-2' : 'fas fa-exclamation-triangle mr-2';
	
	const span = document.createElement('span');
	span.textContent = message;
	
	alertContent.appendChild(icon);
	alertContent.appendChild(span);
	alertDiv.appendChild(alertContent);
	
	const container = document.querySelector('.content-container');
	container.insertBefore(alertDiv, container.querySelector('.card:nth-child(2)'));
	
	setTimeout(() => {
		alertDiv.remove();
	}, timeout);
}
