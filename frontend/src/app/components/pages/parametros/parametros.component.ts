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
	activeTab: string = 'parametros-economicos';
	
	// Datos
	parametrosEconomicos: any[] = [];
	itemsCobro: any[] = [];
	materias: any[] = [];
	
	// Estados de carga
	loading = {
		parametros: false,
		items: false,
		materias: false
	};
	
	// Formularios
	parametroForm: FormGroup;
	itemForm: FormGroup;
	materiaForm: FormGroup;
	
	// Estados de modales
	showParametroModal = false;
	showItemModal = false;
	showMateriaModal = false;
	showDeleteModal = false;
	
	// Datos para eliminación
	deleteData = {
		id: '',
		type: '',
		sigla: '',
		pensum: ''
	};
	
	// Filtros de búsqueda
	searchFilters = {
		parametros: '',
		items: '',
		materias: ''
	};
	
	// Estados de edición
	editingParametro: any = null;
	editingItem: any = null;
	editingMateria: any = null;
	
	// Mensajes
	alertMessage = '';
	alertType = '';
	showAlert = false;

	constructor(private formBuilder: FormBuilder) {
		this.parametroForm = this.formBuilder.group({
			nombre: ['', [Validators.required]],
			descripcion: [''],
			valor: ['', [Validators.required]],
			tipo: ['', [Validators.required]],
			estado: [true]
		});

		this.itemForm = this.formBuilder.group({
			nombre_servicio: ['', [Validators.required]],
			codigo_producto_interno: ['', [Validators.required]],
			codigo_producto_impuesto: [''],
			unidad_medida: ['', [Validators.required]],
			costo: [''],
			nro_creditos: [0, [Validators.required]],
			facturado: [false],
			actividad_economica: ['', [Validators.required]],
			id_parametro_economico: ['', [Validators.required]],
			tipo_item: ['', [Validators.required]],
			descripcion: [''],
			estado: [true]
		});

		this.materiaForm = this.formBuilder.group({
			sigla: ['', [Validators.required]],
			pensum: ['', [Validators.required]],
			nombre: ['', [Validators.required]],
			creditos: [0, [Validators.required]],
			horas_teoricas: [0],
			horas_practicas: [0],
			id_parametro_economico: ['', [Validators.required]],
			descripcion: [''],
			estado: [true]
		});
	}

	ngOnInit(): void {
		this.loadAllData();
	}

	loadAllData(): void {
		this.loadParametrosEconomicos();
		this.loadItemsCobro();
		this.loadMaterias();
	}

	// Gestión de pestañas
	setActiveTab(tab: string): void {
		this.activeTab = tab;
	}

	// Parámetros Económicos
	loadParametrosEconomicos(): void {
		this.loading.parametros = true;
		// Simulación de datos - aquí iría la llamada al servicio
		setTimeout(() => {
			this.parametrosEconomicos = [
				{
					id_parametro_economico: 1,
					nombre: 'Costo por Crédito',
					descripcion: 'Costo unitario por crédito académico',
					valor: 150,
					tipo: 'MONETARIO',
					estado: true
				},
				{
					id_parametro_economico: 2,
					nombre: 'Descuento Estudiante',
					descripcion: 'Porcentaje de descuento para estudiantes',
					valor: 10,
					tipo: 'PORCENTAJE',
					estado: true
				}
			];
			this.loading.parametros = false;
		}, 1000);
	}

	loadItemsCobro(): void {
		this.loading.items = true;
		// Simulación de datos - aquí iría la llamada al servicio
		setTimeout(() => {
			this.itemsCobro = [
				{
					id_item: 1,
					codigo_producto_interno: 'MAT001',
					nombre_servicio: 'Matrícula Semestral',
					costo: 500,
					unidad_medida: 'UNIDAD',
					tipo_item: 'SERVICIO',
					estado: true,
					nro_creditos: 0,
					facturado: true,
					actividad_economica: '85421',
					id_parametro_economico: 1
				}
			];
			this.loading.items = false;
		}, 1000);
	}

	loadMaterias(): void {
		this.loading.materias = true;
		// Simulación de datos - aquí iría la llamada al servicio
		setTimeout(() => {
			this.materias = [
				{
					sigla: 'MAT101',
					pensum: '2024',
					nombre: 'Matemáticas I',
					creditos: 4,
					horas_teoricas: 60,
					horas_practicas: 30,
					id_parametro_economico: 1,
					estado: true
				}
			];
			this.loading.materias = false;
		}, 1000);
	}

	// Filtros
	get filteredParametros() {
		return this.parametrosEconomicos.filter(p => 
			p.nombre.toLowerCase().includes(this.searchFilters.parametros.toLowerCase()) ||
			p.descripcion.toLowerCase().includes(this.searchFilters.parametros.toLowerCase())
		);
	}

	get filteredItems() {
		return this.itemsCobro.filter(item => 
			item.nombre_servicio.toLowerCase().includes(this.searchFilters.items.toLowerCase()) ||
			item.codigo_producto_interno.toLowerCase().includes(this.searchFilters.items.toLowerCase())
		);
	}

	get filteredMaterias() {
		return this.materias.filter(materia => 
			materia.nombre.toLowerCase().includes(this.searchFilters.materias.toLowerCase()) ||
			materia.sigla.toLowerCase().includes(this.searchFilters.materias.toLowerCase())
		);
	}

	// Gestión de modales
	openParametroModal(parametro?: any): void {
		this.editingParametro = parametro;
		if (parametro) {
			this.parametroForm.patchValue(parametro);
		} else {
			this.parametroForm.reset();
			this.parametroForm.patchValue({ estado: true });
		}
		this.showParametroModal = true;
	}

	openItemModal(item?: any): void {
		this.editingItem = item;
		if (item) {
			this.itemForm.patchValue(item);
		} else {
			this.itemForm.reset();
			this.itemForm.patchValue({ estado: true, nro_creditos: 0, facturado: false });
		}
		this.showItemModal = true;
	}

	openMateriaModal(materia?: any): void {
		this.editingMateria = materia;
		if (materia) {
			this.materiaForm.patchValue(materia);
		} else {
			this.materiaForm.reset();
			this.materiaForm.patchValue({ 
				estado: true, 
				creditos: 0, 
				horas_teoricas: 0, 
				horas_practicas: 0 
			});
		}
		this.showMateriaModal = true;
	}

	closeModals(): void {
		this.showParametroModal = false;
		this.showItemModal = false;
		this.showMateriaModal = false;
		this.showDeleteModal = false;
	}

	// Operaciones CRUD
	saveParametro(): void {
		if (this.parametroForm.invalid) return;

		const parametroData = this.parametroForm.value;
		
		// Simulación de guardado
		setTimeout(() => {
			if (this.editingParametro) {
				// Actualizar
				const index = this.parametrosEconomicos.findIndex(p => p.id_parametro_economico === this.editingParametro.id_parametro_economico);
				this.parametrosEconomicos[index] = { ...this.editingParametro, ...parametroData };
				this.showAlertMessage('success', 'Parámetro actualizado correctamente');
			} else {
				// Crear nuevo
				const newParametro = {
					id_parametro_economico: Date.now(),
					...parametroData
				};
				this.parametrosEconomicos.push(newParametro);
				this.showAlertMessage('success', 'Parámetro creado correctamente');
			}
			this.closeModals();
		}, 500);
	}

	saveItem(): void {
		if (this.itemForm.invalid) return;

		const itemData = this.itemForm.value;
		
		// Simulación de guardado
		setTimeout(() => {
			if (this.editingItem) {
				// Actualizar
				const index = this.itemsCobro.findIndex(i => i.id_item === this.editingItem.id_item);
				this.itemsCobro[index] = { ...this.editingItem, ...itemData };
				this.showAlertMessage('success', 'Item actualizado correctamente');
			} else {
				// Crear nuevo
				const newItem = {
					id_item: Date.now(),
					...itemData
				};
				this.itemsCobro.push(newItem);
				this.showAlertMessage('success', 'Item creado correctamente');
			}
			this.closeModals();
		}, 500);
	}

	saveMateria(): void {
		if (this.materiaForm.invalid) return;

		const materiaData = this.materiaForm.value;
		
		// Simulación de guardado
		setTimeout(() => {
			if (this.editingMateria) {
				// Actualizar
				const index = this.materias.findIndex(m => 
					m.sigla === this.editingMateria.sigla && m.pensum === this.editingMateria.pensum
				);
				this.materias[index] = { ...this.editingMateria, ...materiaData };
				this.showAlertMessage('success', 'Materia actualizada correctamente');
			} else {
				// Crear nueva
				this.materias.push(materiaData);
				this.showAlertMessage('success', 'Materia creada correctamente');
			}
			this.closeModals();
		}, 500);
	}

	// Confirmación de eliminación
	confirmDelete(id: any, type: string, sigla?: string, pensum?: string): void {
		this.deleteData = { id, type, sigla: sigla || '', pensum: pensum || '' };
		this.showDeleteModal = true;
	}

	executeDelete(): void {
		const { id, type, sigla, pensum } = this.deleteData;
		
		setTimeout(() => {
			switch (type) {
				case 'parametro':
					this.parametrosEconomicos = this.parametrosEconomicos.filter(p => p.id_parametro_economico !== id);
					this.showAlertMessage('success', 'Parámetro eliminado correctamente');
					break;
				case 'item':
					this.itemsCobro = this.itemsCobro.filter(i => i.id_item !== id);
					this.showAlertMessage('success', 'Item eliminado correctamente');
					break;
				case 'materia':
					this.materias = this.materias.filter(m => !(m.sigla === sigla && m.pensum === pensum));
					this.showAlertMessage('success', 'Materia eliminada correctamente');
					break;
			}
			this.closeModals();
		}, 500);
	}

	// Cambiar estado
	toggleStatus(item: any, type: string): void {
		item.estado = !item.estado;
		const statusText = item.estado ? 'activado' : 'desactivado';
		this.showAlertMessage('success', `Estado ${statusText} correctamente`);
	}

	// Utilidades
	showAlertMessage(type: string, message: string): void {
		this.alertType = type;
		this.alertMessage = message;
		this.showAlert = true;
		
		setTimeout(() => {
			this.showAlert = false;
		}, 5000);
	}
}
