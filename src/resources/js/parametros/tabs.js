/**
 * Módulo para manejar la navegación por pestañas
 */
const TabsManager = {
    init: function() {
        console.log('Inicializando TabsManager');
        this.setupTabs();
        this.setupSearch();
    },

    setupTabs: function() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        console.log(`setupTabs: Encontrados ${tabButtons.length} botones y ${tabContents.length} contenidos`);
        
        tabButtons.forEach((button) => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const tabId = button.getAttribute('data-tab');
                console.log(`Tab clicked: ${tabId}`);
                
                // Remover clase active de todos
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Activar el seleccionado
                button.classList.add('active');
                const activeContent = document.getElementById('tab-' + tabId);
                activeContent.classList.add('active');
                console.log(`Tab content activated: tab-${tabId}`);
            });
        });
    },

    setupSearch: function() {
        // Configurar búsqueda para cada tabla
        const searchConfigs = [
            { inputId: 'searchParametrosInput', tableId: 'parametrosTable' },
            { inputId: 'searchItemsInput', tableId: 'itemsTable' },
            { inputId: 'searchMateriasInput', tableId: 'materiasTable' }
        ];
        
        searchConfigs.forEach(function(config) {
            const input = document.getElementById(config.inputId);
            const table = document.getElementById(config.tableId);
            
            if (input && table) {
                console.log(`Configurando búsqueda para ${config.tableId}`);
                
                input.addEventListener('keyup', function() {
                    const filter = input.value.toUpperCase();
                    const rows = table.getElementsByTagName('tr');
                    
                    for (let i = 1; i < rows.length; i++) {
                        let found = false;
                        const cells = rows[i].getElementsByTagName('td');
                        
                        for (let j = 0; j < cells.length; j++) {
                            const cell = cells[j];
                            if (cell && cell.textContent.toUpperCase().indexOf(filter) > -1) {
                                found = true;
                                break;
                            }
                        }
                        
                        rows[i].style.display = found ? '' : 'none';
                    }
                });
            } else {
                console.warn(`No se pudo configurar búsqueda para ${config.tableId}`);
            }
        });
    }
};

export default TabsManager;
