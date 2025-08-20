@extends('layouts.app')

@section('title', 'Parámetros del Sistema')

@section('content')
<!-- Menú de navegación -->
<x-navigation-menu />
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">Parámetros del Sistema</h1>
                    <p class="text-muted">Configuración de parámetros económicos y elementos de cobro</p>
                </div>
                
                <div class="card-body">
                    <!-- Sección de información general -->
                    <div class="info-section mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-card-icon">
                                        <i class="fas fa-cogs"></i>
                                    </div>
                                    <div class="info-card-content">
                                        <h3>Parámetros Económicos</h3>
                                        <p>Tasas, comisiones y descuentos aplicables a las transacciones financieras.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-card-icon">
                                        <i class="fas fa-money-bill"></i>
                                    </div>
                                    <div class="info-card-content">
                                        <h3>Items de Cobro</h3>
                                        <p>Elementos específicos que pueden ser cobrados a los estudiantes.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-card">
                                    <div class="info-card-icon">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="info-card-content">
                                        <h3>Materias</h3>
                                        <p>Asignaturas académicas con sus respectivos costos y créditos.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Navegación por pestañas -->
                    <div class="tab-navigation">
                        <a href="#parametros" class="tab-button active" data-tab="parametros">
                            <i class="fas fa-cogs"></i> Parámetros Económicos
                        </a>
                        <a href="#items" class="tab-button" data-tab="items">
                            <i class="fas fa-money-bill"></i> Items de Cobro
                        </a>
                        <a href="#materias" class="tab-button" data-tab="materias">
                            <i class="fas fa-book"></i> Materias
                        </a>
                    </div>
                    
                    <!-- Contenido de pestañas -->
                    <!-- Pestaña: Parámetros Económicos -->
                    <div class="tab-content active" id="tab-parametros">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="search-container">
                                <input type="text" id="searchParametrosInput" class="search-input" placeholder="Buscar parámetros...">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button id="btnNuevoParametro" class="btn btn-primary" data-action="create" data-type="parametro">
                                <i class="fas fa-plus"></i> Nuevo Parámetro
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table id="parametrosTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Valor</th>
                                        <th>Descripción</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Los datos se cargarán dinámicamente con JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pestaña: Items de Cobro -->
                    <div class="tab-content" id="tab-items">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="search-container">
                                <input type="text" id="searchItemsInput" class="search-input" placeholder="Buscar items...">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button id="btnNuevoItem" class="btn btn-primary" data-action="create" data-type="item">
                                <i class="fas fa-plus"></i> Nuevo Item
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table id="itemsTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Código</th>
                                        <th>Nombre</th>
                                        <th>Costo</th>
                                        <th>Unidad medida</th>
                                        <th>Tipo</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Los datos se cargarán dinámicamente con JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pestaña: Materias -->
                    <div class="tab-content" id="tab-materias">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="search-container">
                                <input type="text" id="searchMateriasInput" class="search-input" placeholder="Buscar materias...">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button id="btnNuevaMateria" class="btn btn-primary" data-action="create" data-type="materia">
                                <i class="fas fa-plus"></i> Nueva Materia
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table id="materiasTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Sigla</th>
                                        <th>Pensum</th>
                                        <th>Nombre</th>
                                        <th>Nombre Oficial</th>
                                        <th>Créditos</th>
                                        <th>Orden</th>
                                        <th>Parámetro Económico</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Los datos se cargarán dinámicamente con JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Modales -->
<!-- Modal para crear/editar parámetro económico -->
<div class="modal fade" id="parametroModal" tabindex="-1" role="dialog" aria-labelledby="parametroModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="parametroModalLabel">Nuevo Parámetro Económico</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="parametroForm">
                    <input type="hidden" id="parametroId" name="id">
                    <div class="form-group">
                        <label for="parametroNombre">Nombre</label>
                        <input type="text" class="form-control" id="parametroNombre" name="nombre" required maxlength="20">
                    </div>
                    <div class="form-group">
                        <label for="parametroValor">Valor</label>
                        <input type="text" class="form-control" id="parametroValor" name="valor" required>
                    </div>
                    <div class="form-group">
                        <label for="parametroDescripcion">Descripción</label>
                        <textarea class="form-control" id="parametroDescripcion" name="descripcion" rows="3" maxlength="50"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="parametroEstado">Estado</label>
                        <select class="form-control" id="parametroEstado" name="estado">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnGuardarParametro">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para crear/editar item de cobro -->
<div class="modal fade" id="itemModal" tabindex="-1" role="dialog" aria-labelledby="itemModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="itemModalLabel">Nuevo Item de Cobro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="itemForm">
                    <input type="hidden" id="itemId" name="id">
                    <div class="form-group">
                        <label for="itemNombre">Nombre del Servicio</label>
                        <input type="text" class="form-control" id="itemNombre" name="nombre_servicio" required>
                    </div>
                    <div class="form-group">
                        <label for="itemCodigo">Código Producto Interno</label>
                        <input type="text" class="form-control" id="itemCodigo" name="codigo_producto_interno" required>
                    </div>
                    <div class="form-group">
                        <label for="itemCodigoImpuesto">Código Producto Impuesto</label>
                        <input type="number" class="form-control" id="itemCodigoImpuesto" name="codigo_producto_impuesto">
                    </div>
                    <div class="form-group">
                        <label for="itemUnidadMedida">Unidad de Medida</label>
                        <input type="number" class="form-control" id="itemUnidadMedida" name="unidad_medida" required>
                    </div>
                    <div class="form-group">
                        <label for="itemCosto">Costo</label>
                        <input type="number" class="form-control" id="itemCosto" name="costo">
                    </div>
                    <div class="form-group">
                        <label for="itemFacturado">Facturado</label>
                        <select class="form-control" id="itemFacturado" name="facturado" required>
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="itemActividadEconomica">Actividad Económica</label>
                        <input type="text" class="form-control" id="itemActividadEconomica" name="actividad_economica" required maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="itemParametroEconomico">Parámetro Económico</label>
                        <select class="form-control" id="itemParametroEconomico" name="id_parametro_economico" required>
                            <option value="">Seleccione un parámetro</option>
                            <!-- Se cargarán dinámicamente -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="itemCreditos">Número de Créditos</label>
                        <input type="number" class="form-control" id="itemCreditos" name="nro_creditos" value="0">
                    </div>
                    <div class="form-group">
                        <label for="itemTipo">Tipo de Item</label>
                        <select class="form-control" id="itemTipo" name="tipo_item">
                            <option value="REGULAR">Regular</option>
                            <option value="ESPECIAL">Especial</option>
                            <option value="DESCUENTO">Descuento</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="itemDescripcion">Descripción</label>
                        <textarea class="form-control" id="itemDescripcion" name="descripcion" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="itemEstado">Estado</label>
                        <select class="form-control" id="itemEstado" name="estado">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnGuardarItem">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para crear/editar materia -->
<div class="modal fade" id="materiaModal" tabindex="-1" role="dialog" aria-labelledby="materiaModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="materiaModalLabel">Nueva Materia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="materiaForm">
                    <div class="form-group">
                        <label for="materiaSigla">Sigla</label>
                        <input type="text" class="form-control" id="materiaSigla" name="sigla_materia" required>
                    </div>
                    <div class="form-group">
                        <label for="materiaPensum">Pensum</label>
                        <select class="form-control" id="materiaPensum" name="cod_pensum" required>
                            @foreach($pensums as $pensum)
                                <option value="{{ $pensum->cod_pensum }}">{{ $pensum->cod_pensum }} - {{ $pensum->nombre_pensum }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="materiaNombre">Nombre</label>
                        <input type="text" class="form-control" id="materiaNombre" name="nombre_materia" required>
                    </div>
                    <div class="form-group">
                        <label for="materiaNombreOficial">Nombre Material Oficial</label>
                        <input type="text" class="form-control" id="materiaNombreOficial" name="nombre_material_oficial" required>
                    </div>
                    <div class="form-group">
                        <label for="materiaCreditos">Número de Créditos</label>
                        <input type="number" class="form-control" id="materiaCreditos" name="nro_creditos" required>
                    </div>
                    <div class="form-group">
                        <label for="materiaOrden">Orden</label>
                        <input type="number" class="form-control" id="materiaOrden" name="orden" required min="1">
                    </div>
                    <div class="form-group">
                        <label for="materiaParametroEconomico">Parámetro Económico</label>
                        <select class="form-control" id="materiaParametroEconomico" name="id_parametro_economico" required>
                            <option value="">Seleccione un parámetro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="materiaDescripcion">Descripción</label>
                        <textarea class="form-control" id="materiaDescripcion" name="descripcion" rows="3" maxlength="50"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="materiaEstado">Estado</label>
                        <select class="form-control" id="materiaEstado" name="estado">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnGuardarMateria">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Confirmar Eliminación</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>¿Está seguro que desea eliminar este registro? Esta acción no se puede deshacer.</p>
                <input type="hidden" id="deleteId">
                <input type="hidden" id="deleteType">
                <input type="hidden" id="deleteSigla">
                <input type="hidden" id="deletePensum">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDelete">Eliminar</button>
            </div>
        </div>
    </div>
</div>
</div>

@push('scripts')
@vite([
    'resources/js/parametros/tabs.js',
    'resources/js/parametros/parametros-economicos.js',
    'resources/js/parametros/items-cobro.js',
    'resources/js/parametros/materias.js',
    'resources/js/parametros/index.js'
])
@endpush
