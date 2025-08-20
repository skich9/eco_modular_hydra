/**
 * Módulo para manejar los items de cobro
 */
const ItemsCobro = {
    apiUrl: '/api/items-cobro',
    
    init: function() {
        console.log('Inicializando ItemsCobro');
        this.loadItems();
        this.loadParametrosEconomicos();
        this.setupEventListeners();
    },
    
    setupEventListeners: function() {
        // Botón para guardar item
        document.getElementById('btnGuardarItem').addEventListener('click', () => {
            this.saveItem();
        });
        
        // Botón para crear nuevo item
        document.getElementById('btnNuevoItem').addEventListener('click', () => {
            this.resetForm();
            document.getElementById('itemModalLabel').textContent = 'Nuevo Item de Cobro';
            $('#itemModal').modal('show');
        });
        
        // Botón para confirmar eliminación
        document.getElementById('btnConfirmDelete').addEventListener('click', () => {
            const type = document.getElementById('deleteType').value;
            if (type === 'item') {
                const id = document.getElementById('deleteId').value;
                this.deleteItem(id);
            }
        });
    },
    
    loadItems: function() {
        console.log('Cargando items de cobro');
        
        fetch(this.apiUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error al cargar items de cobro');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    this.renderItems(data.data);
                } else {
                    console.error('Error en la respuesta:', data.message);
                    this.showAlert('error', 'Error al cargar items de cobro: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error al cargar items de cobro:', error);
                this.showAlert('error', 'Error al cargar items de cobro: ' + error.message);
            });
    },
    
    loadParametrosEconomicos: function() {
        fetch('/api/parametros-economicos', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const select = document.getElementById('itemParametroEconomico');
                    select.innerHTML = '<option value="">Seleccione un parámetro</option>';
                    
                    data.data.forEach(parametro => {
                        const option = document.createElement('option');
                        option.value = parametro.id_parametro_economico;
                        option.textContent = `${parametro.nombre} - ${parametro.descripcion || ''}`;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error al cargar parámetros económicos:', error);
            });
    },
    
    renderItems: function(items) {
        const tbody = document.querySelector('#itemsTable tbody');
        tbody.innerHTML = '';
        
        if (items.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="8" class="text-center">No hay items de cobro registrados</td>';
            tbody.appendChild(row);
            return;
        }
        
        items.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${item.id_item}</td>
                <td>${item.codigo_producto_interno}</td>
                <td>${item.nombre_servicio}</td>
                <td>${item.costo}</td>
                <td>${item.unidad_medida || 'N/A'}</td>
                <td>${item.tipo_item}</td>
                <td>
                    <span class="badge badge-${item.estado ? 'success' : 'danger'}">
                        ${item.estado ? 'Activo' : 'Inactivo'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-info btn-edit" data-id="${item.id_item}">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger btn-delete" data-id="${item.id_item}" data-type="item">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button class="btn btn-sm btn-${item.estado ? 'warning' : 'success'} btn-toggle" data-id="${item.id_item}">
                        <i class="fas fa-${item.estado ? 'ban' : 'check'}"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
            
            // Agregar event listeners a los botones
            row.querySelector('.btn-edit').addEventListener('click', () => {
                this.editItem(item.id_item);
            });
            
            row.querySelector('.btn-delete').addEventListener('click', (e) => {
                this.confirmDelete(e.currentTarget.dataset.id, e.currentTarget.dataset.type);
            });
            
            row.querySelector('.btn-toggle').addEventListener('click', () => {
                this.toggleStatus(item.id_item);
            });
        });
    },
    
    editItem: function(id) {
        console.log('Editando item:', id);
        
        fetch(`${this.apiUrl}/${id}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error al obtener item');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const item = data.data;
                    document.getElementById('itemId').value = item.id_item;
                    document.getElementById('itemNombre').value = item.nombre_servicio;
                    document.getElementById('itemCodigo').value = item.codigo_producto_interno;
                    document.getElementById('itemCodigoImpuesto').value = item.codigo_producto_impuesto || '';
                    document.getElementById('itemUnidadMedida').value = item.unidad_medida || '';
                    document.getElementById('itemCosto').value = item.costo || '';
                    document.getElementById('itemCreditos').value = item.nro_creditos || 0;
                    document.getElementById('itemFacturado').value = item.facturado ? '1' : '0';
                    document.getElementById('itemActividadEconomica').value = item.actividad_economica || '';
                    document.getElementById('itemParametroEconomico').value = item.id_parametro_economico || '';
                    document.getElementById('itemTipo').value = item.tipo_item;
                    document.getElementById('itemDescripcion').value = item.descripcion || '';
                    document.getElementById('itemEstado').value = item.estado ? '1' : '0';
                    
                    document.getElementById('itemModalLabel').textContent = 'Editar Item de Cobro';
                    $('#itemModal').modal('show');
                } else {
                    console.error('Error en la respuesta:', data.message);
                    this.showAlert('error', 'Error al cargar item: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error al cargar item:', error);
                this.showAlert('error', 'Error al cargar item: ' + error.message);
            });
    },
    
    saveItem: function() {
        const id = document.getElementById('itemId').value;
        const isNew = id === '';
        
        const item = {
            nombre_servicio: document.getElementById('itemNombre').value,
            codigo_producto_interno: document.getElementById('itemCodigo').value,
            codigo_producto_impuesto: document.getElementById('itemCodigoImpuesto').value || null,
            unidad_medida: parseInt(document.getElementById('itemUnidadMedida').value),
            costo: parseInt(document.getElementById('itemCosto').value) || null,
            nro_creditos: parseFloat(document.getElementById('itemCreditos').value),
            facturado: parseInt(document.getElementById('itemFacturado').value),
            actividad_economica: document.getElementById('itemActividadEconomica').value,
            id_parametro_economico: parseInt(document.getElementById('itemParametroEconomico').value),
            tipo_item: document.getElementById('itemTipo').value,
            descripcion: document.getElementById('itemDescripcion').value,
            estado: parseInt(document.getElementById('itemEstado').value)
        };
        
        // Validación básica
        if (!item.nombre_servicio || !item.codigo_producto_interno || !item.unidad_medida || !item.nro_creditos || !item.actividad_economica || !item.id_parametro_economico) {
            this.showAlert('error', 'Por favor complete todos los campos obligatorios');
            return;
        }
        
        const url = isNew ? this.apiUrl : `${this.apiUrl}/${id}`;
        const method = isNew ? 'POST' : 'PUT';
        
        fetch(url, {
            method: method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin',
            body: JSON.stringify(item)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.message || 'Error al guardar item');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', isNew ? 'Item creado correctamente' : 'Item actualizado correctamente');
                $('#itemModal').modal('hide');
                this.loadItems();
            } else {
                console.error('Error en la respuesta:', data.message);
                this.showAlert('error', 'Error al guardar item: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error al guardar item:', error);
            this.showAlert('error', 'Error al guardar item: ' + error.message);
        });
    },
    
    deleteItem: function(id) {
        console.log('Eliminando item:', id);
        
        fetch(`${this.apiUrl}/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al eliminar item');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', 'Item eliminado correctamente');
                $('#deleteModal').modal('hide');
                this.loadItems();
            } else {
                console.error('Error en la respuesta:', data.message);
                this.showAlert('error', 'Error al eliminar item: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error al eliminar item:', error);
            this.showAlert('error', 'Error al eliminar item: ' + error.message);
        });
    },
    
    toggleStatus: function(id) {
        console.log('Cambiando estado del item:', id);
        
        fetch(`${this.apiUrl}/${id}/toggle-status`, {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al cambiar estado del item');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', 'Estado actualizado correctamente');
                this.loadItems();
            } else {
                console.error('Error en la respuesta:', data.message);
                this.showAlert('error', 'Error al cambiar estado: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error al cambiar estado:', error);
            this.showAlert('error', 'Error al cambiar estado: ' + error.message);
        });
    },
    
    confirmDelete: function(id, type) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteType').value = type;
        $('#deleteModal').modal('show');
    },
    
    resetForm: function() {
        document.getElementById('itemForm').reset();
        document.getElementById('itemId').value = '';
    },
    
    showAlert: function(type, message) {
        // Crear alerta Bootstrap
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
        
        const alertHtml = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                <i class="fas ${iconClass} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Agregar alerta al body
        document.body.insertAdjacentHTML('beforeend', alertHtml);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            const alert = document.body.lastElementChild;
            if (alert && alert.classList.contains('alert')) {
                alert.remove();
            }
        }, 5000);
    }
};

export default ItemsCobro;
