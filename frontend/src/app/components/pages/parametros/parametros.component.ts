import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators, FormsModule } from '@angular/forms';

@Component({
	selector: 'app-parametros',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './parametros.component.html',
	styleUrls: ['./parametros.component.scss']
})
export class ParametrosComponent implements OnInit {
	activeTab: string = 'sistema';
	
	// Datos
	parametrosSistema: any[] = [];
	parametrosEconomicos: any[] = [];
	itemsCobro: any[] = [];
	materias: any[] = [];
	
	// Estados de carga
	loading: boolean = false;
	
	// Filtros de búsqueda
	searchTerm: string = '';
	
	// Estados de modales
	showModal: boolean = false;
	showDeleteModal: boolean = false;
	
	// Estados de edición
	editingItem: any = null;
	itemToDelete: any = null;
	deleteType: string = '';
	
	// Formularios reactivos
	itemForm: FormGroup;
	
	// Mensajes de alerta
	alertMessage: string = '';
	alertType: string = 'success';
	
	constructor(private fb: FormBuilder) {
		this.itemForm = this.fb.group({
			pensum: ['', [Validators.required]],
			parametro: ['', [Validators.required]],
			valor: ['', [Validators.required]],
			estado: ['Activo'],
			modulo: ['', [Validators.required]]
		});
	}

	ngOnInit(): void {
		// Datos de ejemplo para parámetros del sistema (simulando estructura de base de datos)
		this.parametrosSistema = [
			{ id: 1, nombre: 'max_estudiantes', valor: '30', estado: true, descripcion: 'Máximo número de estudiantes por aula', modulo: 'Académico' },
			{ id: 2, nombre: 'duracion_semestre', valor: '18', descripcion: 'Duración del semestre en semanas', estado: true, modulo: 'Académico' },
			{ id: 3, nombre: 'nota_minima', valor: '51', descripcion: 'Nota mínima para aprobar', estado: true, modulo: 'Evaluación' }
		];

		// Datos de ejemplo para parámetros económicos (basado en modelo ParametrosEconomicos)
		this.parametrosEconomicos = [
			{ id_parametro_economico: 1, nombre: 'descuento_hermanos', valor: '10', descripcion: 'Descuento por hermanos estudiando', estado: true },
			{ id_parametro_economico: 2, nombre: 'recargo_mora', valor: '5', descripcion: 'Recargo por mora en pagos', estado: true },
			{ id_parametro_economico: 3, nombre: 'beca_excelencia', valor: '50', descripcion: 'Porcentaje de beca por excelencia académica', estado: true }
		];

		// Datos de ejemplo para items de cobro (basado en modelo ItemsCobro)
		this.itemsCobro = [
			{ 
				id_item: 1, 
				codigo_producto_interno: 'MAT-001', 
				nombre_servicio: 'Matrícula', 
				costo: 500.00, 
				descripcion: 'Costo de matrícula semestral',
				tipo_item: 'Inscripción',
				estado: true,
				id_parametro_economico: 1
			},
			{ 
				id_item: 2, 
				codigo_producto_interno: 'MEN-001', 
				nombre_servicio: 'Mensualidad', 
				costo: 800.00, 
				descripcion: 'Pago mensual de colegiatura',
				tipo_item: 'Recurrente',
				estado: true,
				id_parametro_economico: 2
			},
			{ 
				id_item: 3, 
				codigo_producto_interno: 'LAB-001', 
				nombre_servicio: 'Laboratorio', 
				costo: 150.00, 
				descripcion: 'Costo de uso de laboratorio',
				tipo_item: 'Adicional',
				estado: true,
				id_parametro_economico: 1
			}
		];
	}

	// Método removido - datos cargados en ngOnInit

	// Gestión de pestañas
	setActiveTab(tab: string): void {
		this.activeTab = tab;
		this.searchTerm = '';
	}

	// Filtrado de datos
	getFilteredData(tab: string): any[] {
		let data: any[] = [];
		
		switch (tab) {
			case 'sistema':
				data = this.parametrosSistema;
				break;
			case 'economicos':
				data = this.parametrosEconomicos;
				break;
			case 'items':
				data = this.itemsCobro;
				break;
		}

		if (!this.searchTerm) {
			return data;
		}

		return data.filter(item => 
			Object.values(item).some(value => 
				value?.toString().toLowerCase().includes(this.searchTerm.toLowerCase())
			)
		);
	}

	filterData(): void {
		// La funcionalidad de filtrado se maneja a través del método getFilteredData
	}

	// Gestión de modales
	openCreateModal(type: string): void {
		this.editingItem = null;
		this.itemForm.reset();
		this.showModal = true;
	}

	openEditModal(type: string, item: any): void {
		this.editingItem = item;
		this.itemForm.patchValue(item);
		this.showModal = true;
	}

	openDeleteModal(type: string, item: any): void {
		this.itemToDelete = item;
		this.deleteType = type;
		this.showDeleteModal = true;
	}

	closeModals(): void {
		this.showModal = false;
		this.showDeleteModal = false;
		this.editingItem = null;
		this.itemToDelete = null;
	}

	// Operaciones CRUD
	saveItem(): void {
		if (this.itemForm.valid) {
			const formData = this.itemForm.value;
			
			if (this.editingItem) {
				// Actualizar item existente
				const index = this.getDataArray().findIndex(item => item.id === this.editingItem.id);
				if (index !== -1) {
					this.getDataArray()[index] = { ...this.editingItem, ...formData };
				}
			} else {
				// Crear nuevo item
				const newItem = {
					id: Date.now(),
					...formData
				};
				this.getDataArray().push(newItem);
			}
			
			this.closeModals();
			this.showAlert('Elemento guardado exitosamente', 'success');
		}
	}

	executeDelete(): void {
		if (this.itemToDelete) {
			const dataArray = this.getDataArray();
			const index = dataArray.findIndex(item => item.id === this.itemToDelete.id);
			if (index !== -1) {
				dataArray.splice(index, 1);
			}
			
			this.closeModals();
			this.showAlert('Elemento eliminado exitosamente', 'success');
		}
	}

	private getDataArray(): any[] {
		switch (this.activeTab) {
			case 'sistema':
				return this.parametrosSistema;
			case 'economicos':
				return this.parametrosEconomicos;
			case 'items':
				return this.itemsCobro;
			default:
				return [];
		}
	}

	// Utilidades
	private showAlert(message: string, type: string = 'success'): void {
		this.alertMessage = message;
		this.alertType = type;
		
		setTimeout(() => {
			this.alertMessage = '';
		}, 3000);
	}

	// Cambiar estado
	toggleStatus(item: any, type: string): void {
		item.estado = !item.estado;
		const statusText = item.estado ? 'activado' : 'desactivado';
		this.showAlert(`Estado ${statusText} correctamente`, 'success');
	}
}
