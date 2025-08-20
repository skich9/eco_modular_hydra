/**
 * Módulo para manejar las materias
 */
const Materias = {
    apiUrl: '/api/materias',
    
    init: function() {
        console.log('Inicializando Materias');
        this.loadMaterias();
        this.loadParametrosEconomicos();
        this.setupEventListeners();
    },
    
    setupEventListeners: function() {
        // Botón para guardar materia
        document.getElementById('btnGuardarMateria').addEventListener('click', () => {
            this.saveMateria();
        });
        
        // Botón para crear nueva materia
        document.getElementById('btnNuevaMateria').addEventListener('click', () => {
            this.resetForm();
            document.getElementById('materiaModalLabel').textContent = 'Nueva Materia';
            $('#materiaModal').modal('show');
        });
        
        // Botón para confirmar eliminación
        document.getElementById('btnConfirmDelete').addEventListener('click', () => {
            const type = document.getElementById('deleteType').value;
            if (type === 'materia') {
                const sigla = document.getElementById('deleteSigla').value;
                const pensum = document.getElementById('deletePensum').value;
                this.deleteMateria(sigla, pensum);
            }
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
                    const select = document.getElementById('materiaParametroEconomico');
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
    
    loadMaterias: function() {
        console.log('Cargando materias');
        
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
                    throw new Error('Error al cargar materias');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    this.renderMaterias(data.data);
                } else {
                    console.error('Error en la respuesta:', data.message);
                    this.showAlert('error', 'Error al cargar materias: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error al cargar materias:', error);
                this.showAlert('error', 'Error al cargar materias: ' + error.message);
            });
    },
    
    renderMaterias: function(materias) {
        const tbody = document.querySelector('#materiasTable tbody');
        tbody.innerHTML = '';
        
        if (materias.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="9" class="text-center">No hay materias registradas</td>';
            tbody.appendChild(row);
            return;
        }
        
        materias.forEach(materia => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${materia.sigla_materia}</td>
                <td>${materia.cod_pensum}</td>
                <td>${materia.nombre_materia}</td>
                <td>${materia.nombre_material_oficial || 'N/A'}</td>
                <td>${materia.nro_creditos}</td>
                <td>${materia.orden || 'N/A'}</td>
                <td>${materia.parametro_economico ? materia.parametro_economico.nombre : 'N/A'}</td>
                <td>
                    <span class="badge badge-${materia.estado ? 'success' : 'danger'}">
                        ${materia.estado ? 'Activo' : 'Inactivo'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-sm btn-info btn-edit" data-sigla="${materia.sigla_materia}" data-pensum="${materia.cod_pensum}">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger btn-delete" data-sigla="${materia.sigla_materia}" data-pensum="${materia.cod_pensum}" data-type="materia">
                        <i class="fas fa-trash"></i>
                    </button>
                    <button class="btn btn-sm btn-${materia.estado ? 'warning' : 'success'} btn-toggle" data-sigla="${materia.sigla_materia}" data-pensum="${materia.cod_pensum}">
                        <i class="fas fa-${materia.estado ? 'ban' : 'check'}"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
            
            // Agregar event listeners a los botones
            row.querySelector('.btn-edit').addEventListener('click', (e) => {
                this.editMateria(
                    e.currentTarget.dataset.sigla, 
                    e.currentTarget.dataset.pensum
                );
            });
            
            row.querySelector('.btn-delete').addEventListener('click', (e) => {
                this.confirmDelete(
                    e.currentTarget.dataset.sigla, 
                    e.currentTarget.dataset.pensum, 
                    e.currentTarget.dataset.type
                );
            });
            
            row.querySelector('.btn-toggle').addEventListener('click', (e) => {
                this.toggleStatus(
                    e.currentTarget.dataset.sigla, 
                    e.currentTarget.dataset.pensum
                );
            });
        });
    },
    
    editMateria: function(sigla, pensum) {
        console.log('Editando materia:', sigla, pensum);
        
        fetch(`${this.apiUrl}/${sigla}/${pensum}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error al obtener materia');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const materia = data.data;
                    document.getElementById('materiaSigla').value = materia.sigla_materia;
                    document.getElementById('materiaSigla').readOnly = true; // No se puede cambiar la sigla
                    document.getElementById('materiaPensum').value = materia.cod_pensum;
                    document.getElementById('materiaPensum').disabled = true; // No se puede cambiar el pensum
                    document.getElementById('materiaNombre').value = materia.nombre_materia;
                    document.getElementById('materiaNombreOficial').value = materia.nombre_material_oficial || '';
                    document.getElementById('materiaCreditos').value = materia.nro_creditos;
                    document.getElementById('materiaOrden').value = materia.orden || '';
                    document.getElementById('materiaParametroEconomico').value = materia.id_parametro_economico || '';
                    document.getElementById('materiaDescripcion').value = materia.descripcion || '';
                    document.getElementById('materiaEstado').value = materia.estado ? '1' : '0';
                    
                    document.getElementById('materiaModalLabel').textContent = 'Editar Materia';
                    $('#materiaModal').modal('show');
                } else {
                    console.error('Error en la respuesta:', data.message);
                    this.showAlert('error', 'Error al cargar materia: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error al cargar materia:', error);
                this.showAlert('error', 'Error al cargar materia: ' + error.message);
            });
    },
    
    saveMateria: function() {
        const sigla = document.getElementById('materiaSigla').value;
        const pensum = document.getElementById('materiaPensum').value;
        const isNew = !document.getElementById('materiaSigla').readOnly;
        
        const materia = {
            sigla_materia: sigla,
            cod_pensum: pensum,
            nombre_materia: document.getElementById('materiaNombre').value,
            nombre_material_oficial: document.getElementById('materiaNombreOficial').value,
            nro_creditos: document.getElementById('materiaCreditos').value,
            orden: document.getElementById('materiaOrden').value,
            id_parametro_economico: parseInt(document.getElementById('materiaParametroEconomico').value),
            descripcion: document.getElementById('materiaDescripcion').value,
            estado: document.getElementById('materiaEstado').value
        };
        
        // Validación básica
        if (!materia.sigla_materia || !materia.cod_pensum || !materia.nombre_materia || !materia.nombre_material_oficial || !materia.nro_creditos || !materia.orden || !materia.id_parametro_economico) {
            this.showAlert('error', 'Por favor complete los campos obligatorios (Sigla, Pensum, Nombre, Nombre Oficial, Créditos, Orden y Parámetro Económico)');
            return;
        }
        
        const url = isNew ? this.apiUrl : `${this.apiUrl}/${sigla}/${pensum}`;
        const method = isNew ? 'POST' : 'PUT';
        
        fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(materia)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.message || 'Error al guardar materia');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', isNew ? 'Materia creada correctamente' : 'Materia actualizada correctamente');
                $('#materiaModal').modal('hide');
                this.loadMaterias();
            } else {
                console.error('Error en la respuesta:', data.message);
                this.showAlert('error', 'Error al guardar materia: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error al guardar materia:', error);
            this.showAlert('error', 'Error al guardar materia: ' + error.message);
        });
    },
    
    deleteMateria: function(sigla, pensum) {
        console.log('Eliminando materia:', sigla, pensum);
        
        fetch(`${this.apiUrl}/${sigla}/${pensum}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al eliminar materia');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', 'Materia eliminada correctamente');
                $('#deleteModal').modal('hide');
                this.loadMaterias();
            } else {
                console.error('Error en la respuesta:', data.message);
                this.showAlert('error', 'Error al eliminar materia: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error al eliminar materia:', error);
            this.showAlert('error', 'Error al eliminar materia: ' + error.message);
        });
    },
    
    toggleStatus: function(sigla, pensum) {
        console.log('Cambiando estado de la materia:', sigla, pensum);
        
        fetch(`${this.apiUrl}/${sigla}/${pensum}/toggle-status`, {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al cambiar estado de la materia');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.showAlert('success', 'Estado actualizado correctamente');
                this.loadMaterias();
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
    
    confirmDelete: function(sigla, pensum, type) {
        document.getElementById('deleteSigla').value = sigla;
        document.getElementById('deletePensum').value = pensum;
        document.getElementById('deleteType').value = type;
        $('#deleteModal').modal('show');
    },
    
    resetForm: function() {
        document.getElementById('materiaForm').reset();
        document.getElementById('materiaSigla').readOnly = false;
        document.getElementById('materiaPensum').disabled = false;
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

export default Materias;
