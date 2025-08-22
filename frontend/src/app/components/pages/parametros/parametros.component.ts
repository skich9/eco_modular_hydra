import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ParametrosEconomicosService } from '../../../services/parametros-economicos.service';
import { ItemsCobroService } from '../../../services/items-cobro.service';
import { ParametroEconomico } from '../../../models/parametro-economico.model';
import { ItemCobro } from '../../../models/item-cobro.model';

@Component({
	selector: 'app-parametros',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './parametros.component.html',
	styleUrls: ['./parametros.component.scss']
})
export class ParametrosComponent implements OnInit {
	activeTab: string = 'parametros-economicos';
	
	// Arrays para datos
	parametrosEconomicos: ParametroEconomico[] = [];
	itemsCobro: ItemCobro[] = [];
	
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
	
	// Soporte PK compuesta: guardar nombre original al editar
	originalNombrePE: string | null = null;
	
	// Formularios reactivos
	parametroEconomicoForm: FormGroup;
	itemCobroForm: FormGroup;
	
	// Mensajes de alerta
	alertMessage: string = '';
	alertType: string = 'success';
	
	constructor(
		private fb: FormBuilder,
		private parametrosEconomicosService: ParametrosEconomicosService,
		private itemsCobroService: ItemsCobroService
	) {
		// Formulario para parámetros económicos
		this.parametroEconomicoForm = this.fb.group({
			id_parametro_economico: [null],
			nombre: ['', [Validators.required, Validators.maxLength(20)]],
			valor: ['', [Validators.required, Validators.maxLength(255)]],
			descripcion: ['', [Validators.required, Validators.maxLength(255)]],
			estado: [true]
		});

		// Formulario para items de cobro
		this.itemCobroForm = this.fb.group({
			id_item: [null],
			codigo_producto_impuesto: [null],
			codigo_producto_interno: ['', [Validators.required, Validators.maxLength(15)]],
			unidad_medida: [null, Validators.required],
			nombre_servicio: ['', [Validators.required, Validators.maxLength(100)]],
			nro_creditos: [0, [Validators.required, Validators.min(0)]],
			costo: [null, Validators.min(0)],
			facturado: [false],
			actividad_economica: ['', Validators.required],
			descripcion: [''],
			tipo_item: ['', [Validators.required, Validators.maxLength(40)]],
			estado: [true],
			id_parametro_economico: [null, Validators.required]
		});
	}

	ngOnInit(): void {
		this.loadAllData();
	}

	// Cargar todos los datos desde las APIs
	loadAllData(): void {
		this.loading = true;
		this.loadParametrosEconomicos();
		this.loadItemsCobro();
	}

	// Cargar parámetros económicos
	loadParametrosEconomicos(): void {
		this.parametrosEconomicosService.getAll().subscribe({
			next: (response) => {
				if (response.success) {
					this.parametrosEconomicos = response.data;
				} else {
					this.showAlert('Error al cargar parámetros económicos', 'error');
				}
			},
			error: (error) => {
				console.error('Error al cargar parámetros económicos:', error);
				// Usar datos simulados si falla la API
				this.parametrosEconomicos = [
					{
						id_parametro_economico: 1,
						nombre: 'descuento_hermanos',
						valor: '10',
						descripcion: 'Descuento por hermanos estudiando',
						estado: true
					},
					{
						id_parametro_economico: 2,
						nombre: 'recargo_mora',
						valor: '5',
						descripcion: 'Recargo por mora en pagos',
						estado: true
					}
				];
				this.showAlert('Usando datos simulados - Backend no disponible', 'warning');
			}
		});
	}

	// Cargar items de cobro
	loadItemsCobro(): void {
		this.itemsCobroService.getAll().subscribe({
			next: (response) => {
				if (response.success) {
					this.itemsCobro = response.data;
				} else {
					this.showAlert('Error al cargar items de cobro', 'error');
				}
				this.loading = false;
			},
			error: (error) => {
				console.error('Error al cargar items de cobro:', error);
				// Usar datos simulados si falla la API
				this.itemsCobro = [
					{
						id_item: 1,
						codigo_producto_interno: 'MAT-001',
						unidad_medida: 1,
						nombre_servicio: 'Matrícula',
						nro_creditos: 0,
						costo: 500,
						facturado: true,
						actividad_economica: '854200',
						descripcion: 'Costo de matrícula semestral',
						tipo_item: 'Inscripción',
						estado: true,
						id_parametro_economico: 1
					}
				];
				this.loading = false;
				this.showAlert('Usando datos simulados - Backend no disponible', 'warning');
			}
		});
	}

	// Gestión de pestañas
	setActiveTab(tab: string): void {
		this.activeTab = tab;
		this.searchTerm = '';
	}

	// Filtrado de datos
	getFilteredParametrosEconomicos(): ParametroEconomico[] {
		if (!this.searchTerm) {
			return this.parametrosEconomicos;
		}
		return this.parametrosEconomicos.filter(item =>
			item.nombre.toLowerCase().includes(this.searchTerm.toLowerCase()) ||
			(item.descripcion && item.descripcion.toLowerCase().includes(this.searchTerm.toLowerCase()))
		);
	}

	getFilteredItemsCobro(): ItemCobro[] {
		if (!this.searchTerm) {
			return this.itemsCobro;
		}
		return this.itemsCobro.filter(item =>
			item.nombre_servicio.toLowerCase().includes(this.searchTerm.toLowerCase()) ||
			item.codigo_producto_interno.toLowerCase().includes(this.searchTerm.toLowerCase()) ||
			(item.descripcion && item.descripcion.toLowerCase().includes(this.searchTerm.toLowerCase()))
		);
	}

	// CRUD Parámetros Económicos
	openParametroEconomicoModal(parametro?: ParametroEconomico): void {
		this.editingItem = parametro || null;
		if (parametro) {
			this.originalNombrePE = parametro.nombre;
			this.parametroEconomicoForm.patchValue(parametro);
		} else {
			this.originalNombrePE = null;
			this.parametroEconomicoForm.reset();
			this.parametroEconomicoForm.patchValue({ estado: true });
		}
		this.showModal = true;
	}

	saveParametroEconomico(): void {
		if (this.parametroEconomicoForm.valid) {
			const parametro = this.parametroEconomicoForm.value;
			
			if (this.editingItem) {
				// Actualizar
				this.parametrosEconomicosService.update(this.editingItem.id_parametro_economico, parametro, this.originalNombrePE || this.editingItem.nombre).subscribe({
					next: (response) => {
						if (response.success) {
							this.loadParametrosEconomicos();
							this.closeModal();
							this.showAlert('Parámetro actualizado correctamente', 'success');
						}
					},
					error: (error) => {
						console.error('Error al actualizar:', error);
						this.showAlert('Error al actualizar parámetro', 'error');
					}
				});
			} else {
				// Crear
				this.parametrosEconomicosService.create(parametro).subscribe({
					next: (response) => {
						if (response.success) {
							this.loadParametrosEconomicos();
							this.closeModal();
							this.showAlert('Parámetro creado correctamente', 'success');
						}
					},
					error: (error) => {
						console.error('Error al crear:', error);
						this.showAlert('Error al crear parámetro', 'error');
					}
				});
			}
		}
	}

	// CRUD Items de Cobro
	openItemCobroModal(item?: ItemCobro): void {
		this.editingItem = item || null;
		if (item) {
			this.itemCobroForm.patchValue(item);
		} else {
			this.itemCobroForm.reset();
			this.itemCobroForm.patchValue({ estado: true, facturado: false, nro_creditos: 0 });
		}
		this.showModal = true;
	}

	saveItemCobro(): void {
		if (this.itemCobroForm.valid) {
			const item = this.itemCobroForm.value;
			
			if (this.editingItem) {
				// Actualizar
				this.itemsCobroService.update(this.editingItem.id_item, item).subscribe({
					next: (response) => {
						if (response.success) {
							this.loadItemsCobro();
							this.closeModal();
							this.showAlert('Item actualizado correctamente', 'success');
						}
					},
					error: (error) => {
						console.error('Error al actualizar:', error);
						this.showAlert('Error al actualizar item', 'error');
					}
				});
			} else {
				// Crear
				this.itemsCobroService.create(item).subscribe({
					next: (response) => {
						if (response.success) {
							this.loadItemsCobro();
							this.closeModal();
							this.showAlert('Item creado correctamente', 'success');
						}
					},
					error: (error) => {
						console.error('Error al crear:', error);
						this.showAlert('Error al crear item', 'error');
					}
				});
			}
		}
	}

	// Eliminar
	confirmDelete(item: any, type: string): void {
		this.itemToDelete = item;
		this.deleteType = type;
		this.showDeleteModal = true;
	}

	deleteItem(): void {
		if (this.deleteType === 'parametro-economico') {
			this.parametrosEconomicosService.delete(this.itemToDelete.id_parametro_economico, this.itemToDelete.nombre).subscribe({
				next: (response) => {
					if (response.success) {
						this.loadParametrosEconomicos();
						this.closeDeleteModal();
						this.showAlert('Parámetro eliminado correctamente', 'success');
					}
				},
				error: (error) => {
					console.error('Error al eliminar:', error);
					this.showAlert('Error al eliminar parámetro', 'error');
				}
			});
		} else if (this.deleteType === 'item-cobro') {
			this.itemsCobroService.delete(this.itemToDelete.id_item).subscribe({
				next: (response) => {
					if (response.success) {
						this.loadItemsCobro();
						this.closeDeleteModal();
						this.showAlert('Item eliminado correctamente', 'success');
					}
				},
				error: (error) => {
					console.error('Error al eliminar:', error);
					this.showAlert('Error al eliminar item', 'error');
				}
			});
		}
	}

	// Toggle estado
	toggleStatus(item: any, type: string): void {
		if (type === 'parametro-economico') {
			this.parametrosEconomicosService.toggleStatus(item.id_parametro_economico, item.nombre).subscribe({
				next: (response) => {
					if (response.success) {
						this.loadParametrosEconomicos();
						this.showAlert('Estado actualizado correctamente', 'success');
					}
				},
				error: (error) => {
					console.error('Error al cambiar estado:', error);
					this.showAlert('Error al cambiar estado', 'error');
				}
			});
		} else if (type === 'item-cobro') {
			this.itemsCobroService.toggleStatus(item.id_item).subscribe({
				next: (response) => {
					if (response.success) {
						this.loadItemsCobro();
						this.showAlert('Estado actualizado correctamente', 'success');
					}
				},
				error: (error) => {
					console.error('Error al cambiar estado:', error);
					this.showAlert('Error al cambiar estado', 'error');
				}
			});
		}
	}

	// Gestión de modales
	closeModal(): void {
		this.showModal = false;
		this.editingItem = null;
		this.originalNombrePE = null;
		this.parametroEconomicoForm.reset();
		this.itemCobroForm.reset();
	}

	closeDeleteModal(): void {
		this.showDeleteModal = false;
		this.itemToDelete = null;
		this.deleteType = '';
	}

	// Alertas
	showAlert(message: string, type: string): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => {
			this.alertMessage = '';
		}, 5000);
	}
}
