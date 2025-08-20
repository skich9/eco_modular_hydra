/**
 * Archivo principal para la página de Parámetros del Sistema
 * Integra todos los módulos y maneja la inicialización
 */
import TabsManager from './tabs';
import ParametrosEconomicos from './parametros-economicos';
import ItemsCobro from './items-cobro';
import Materias from './materias';

const ParametrosSistema = {
    init: function() {
        console.log('Inicializando ParametrosSistema');
        
        // Inicializar gestor de pestañas
        TabsManager.init();
        
        // Inicializar módulos de datos
        ParametrosEconomicos.init();
        ItemsCobro.init();
        Materias.init();
        
        // Configurar funciones globales para modales
        this.setupGlobalModalFunctions();
        
        console.log('Todos los módulos inicializados correctamente');
    },
    
    setupGlobalModalFunctions: function() {
        // Función global para confirmar eliminación
        window.confirmDelete = function(id, type, sigla = '', pensum = '') {
            $('#deleteId').val(id);
            $('#deleteType').val(type);
            $('#deleteSigla').val(sigla);
            $('#deletePensum').val(pensum);
            $('#deleteModal').modal('show');
        };
        
        // Configurar botón de confirmación de eliminación
        $('#btnConfirmDelete').off('click').on('click', function() {
            const id = $('#deleteId').val();
            const type = $('#deleteType').val();
            const sigla = $('#deleteSigla').val();
            const pensum = $('#deletePensum').val();
            
            let deleteFunction;
            switch(type) {
                case 'parametro':
                    deleteFunction = ParametrosEconomicos.delete;
                    break;
                case 'item':
                    deleteFunction = ItemsCobro.delete;
                    break;
                case 'materia':
                    deleteFunction = Materias.delete;
                    break;
            }
            
            if (deleteFunction) {
                deleteFunction(id, sigla, pensum);
            }
            
            $('#deleteModal').modal('hide');
        });
    }
};

// Inicializar cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => ParametrosSistema.init());
} else {
    ParametrosSistema.init();
}

export default ParametrosSistema;
