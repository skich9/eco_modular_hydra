import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { DatosMoraService } from '../../../services/datos-mora.service';
import { CuotaService } from '../../../services/cuota.service';
import { CarreraService } from '../../../services/carrera.service';
import { GestionService } from '../../../services/gestion.service';
import { DatosMora } from '../../../models/datos-mora.model';
import { DatosMoraDetalle } from '../../../models/datos-mora-detalle.model';
import { Cuota } from '../../../models/cuota.model';
import { forkJoin } from 'rxjs';

@Component({
	selector: 'app-configuracion-mora',
	standalone: true,
	imports: [CommonModule, ReactiveFormsModule, FormsModule],
	templateUrl: './configuracion-mora.component.html',
	styleUrls: ['./configuracion-mora.component.scss']
})
export class ConfiguracionMoraComponent implements OnInit {
	// Datos
	configuraciones: DatosMoraDetalle[] = [];
	gestiones: any[] = [];
	carreras: any[] = [];
	carrerasPensums: Map<string, any[]> = new Map();
	pensums: any[] = [];
	todasLasCuotas: Cuota[] = [];
	cuotasFiltradas: Cuota[] = [];

	// Búsqueda
	searchText = '';

	// Formulario
	moraForm: FormGroup;
	semestresGroup: FormGroup;
	cuotasGroup: FormGroup;

	// Estado UI
	loading = false;
	alertMessage = '';
	alertType: 'success' | 'error' | 'warning' = 'success';

	// Modales
	showMoraModal = false;

	// Edición
	editingMora: DatosMoraDetalle | null = null;

	constructor(
		private fb: FormBuilder,
		private moraService: DatosMoraService,
		private cuotaService: CuotaService,
		private carreraService: CarreraService,
		private gestionService: GestionService
	) {
		// Grupo de semestres (checkboxes)
		this.semestresGroup = this.fb.group({
			marcarTodosSemestres: [false],
			sem1: [false],
			sem2: [false],
			sem3: [false],
			sem4: [false],
			sem5: [false],
			sem6: [false]
		});

		// Grupo de cuotas (checkboxes hardcodeados 1-5)
		this.cuotasGroup = this.fb.group({
			marcarTodasCuotas: [false],
			cuota_1: [false],
			cuota_2: [false],
			cuota_3: [false],
			cuota_4: [false],
			cuota_5: [false]
		});

		const currentYear = new Date().getFullYear();
		const today = new Date().toISOString().split('T')[0];

		this.moraForm = this.fb.group({
			id_datos_mora_detalle: [null],
			gestion: [currentYear.toString(), Validators.required],
			carrera: ['', Validators.required],
			pensum: ['', Validators.required],
			monto: [null, [Validators.required, Validators.min(0)]],
			fecha_inicio: [today, Validators.required],
			fecha_fin: [''],
			activo: [true]
		});
	}

	ngOnInit(): void {
		this.loadConfiguraciones();
		this.setupCheckboxListeners();
	}

	precargarDatosModal(): void {
		this.loading = true;

		forkJoin({
			gestiones: this.gestionService.getActivas(),
			carreras: this.carreraService.getAll()
		}).subscribe({
			next: (res) => {
				// Gestiones activas
				if (res.gestiones.success) {
					this.gestiones = res.gestiones.data;
				}

				// Carreras
				if (res.carreras.success) {
					this.carreras = res.carreras.data;

					// Precargar pensums de todas las carreras
					const pensumRequests = this.carreras.map(carrera =>
						this.carreraService.getPensums(carrera.codigo_carrera)
					);

					forkJoin(pensumRequests).subscribe({
						next: (pensumResults) => {
							pensumResults.forEach((pensumRes, index) => {
								if (pensumRes.success) {
									const carreraCode = this.carreras[index].codigo_carrera;
									this.carrerasPensums.set(carreraCode, pensumRes.data);
								}
							});
						},
						error: (err) => {
							console.error('Error precargando pensums:', err);
						}
					});
				}

				// Cuotas ya están hardcodeadas en cuotasGroup (1-5)

				this.loading = false;
			},
			error: (err) => {
				console.error('Error precargando datos:', err);
				this.loading = false;
			}
		});
	}

	onCarreraChange(): void {
		const carreraCode = this.moraForm.get('carrera')?.value;
		if (carreraCode && this.carrerasPensums.has(carreraCode)) {
			this.pensums = this.carrerasPensums.get(carreraCode) || [];
		} else {
			this.pensums = [];
		}
		this.moraForm.patchValue({ pensum: '' });
	}

	setupCheckboxListeners(): void {
		// Listener para "Marcar Todos Semestres"
		this.semestresGroup.get('marcarTodosSemestres')?.valueChanges.subscribe(checked => {
			['sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6'].forEach(sem => {
				this.semestresGroup.get(sem)?.setValue(checked, { emitEvent: false });
			});
		});

		// Listener para "Marcar Todas Cuotas" (hardcodeadas 1-5)
		this.cuotasGroup.get('marcarTodasCuotas')?.valueChanges.subscribe(checked => {
			['cuota_1', 'cuota_2', 'cuota_3', 'cuota_4', 'cuota_5'].forEach(cuota => {
				this.cuotasGroup.get(cuota)?.setValue(checked, { emitEvent: false });
			});
		});
	}

	loadConfiguraciones(): void {
		this.loading = true;
		this.moraService.getAllDetalles().subscribe({
			next: (res) => {
				if (res.success) {
					this.configuraciones = res.data;
				} else {
					this.showAlert('Error al cargar configuraciones de mora', 'error');
				}
				this.loading = false;
			},
			error: (err) => {
				console.error('Mora getAll:', err);
				this.showAlert('No se pudo cargar las configuraciones de mora', 'error');
				this.loading = false;
			}
		});
	}

	// Filtros
	get filteredConfiguraciones(): DatosMoraDetalle[] {
		const t = (this.searchText || '').toLowerCase().trim();
		if (!t) return this.configuraciones;
		return this.configuraciones.filter(c =>
			c.semestre.toLowerCase().includes(t) ||
			(c.cuota && c.cuota.toString().includes(t)) ||
			(c.cod_pensum && c.cod_pensum.toLowerCase().includes(t))
		);
	}

	// Modales
	openNewMora(): void {
		this.editingMora = null;
		const currentYear = new Date().getFullYear();
		const today = new Date().toISOString().split('T')[0];

		// Precargar datos del modal
		this.precargarDatosModal();

		// Resetear formulario principal
		this.moraForm.reset({
			gestion: currentYear.toString(),
			carrera: '',
			pensum: '',
			monto: null,
			fecha_inicio: today,
			fecha_fin: '',
			activo: true
		});

		// Resetear checkboxes de semestres y cuotas
		this.semestresGroup.reset({
			marcarTodosSemestres: false,
			sem1: false,
			sem2: false,
			sem3: false,
			sem4: false,
			sem5: false,
			sem6: false
		});

		this.cuotasGroup.reset({
			marcarTodasCuotas: false,
			cuota_1: false,
			cuota_2: false,
			cuota_3: false,
			cuota_4: false,
			cuota_5: false
		});

		this.showMoraModal = true;
	}

	openEditMora(mora: DatosMoraDetalle): void {
		this.editingMora = mora;
		this.moraForm.patchValue(mora);
		// TODO: Cargar semestres y cuotas seleccionados si se implementa edición
		this.showMoraModal = true;
	}

	closeModals(): void {
		this.showMoraModal = false;
		this.editingMora = null;
	}

	// Guardar
	saveMora(): void {
		if (!this.moraForm.valid) {
			this.showAlert('Por favor complete todos los campos requeridos', 'warning');
			return;
		}

		// Obtener semestres seleccionados
		const semestresSeleccionados: string[] = [];
		['sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6'].forEach((sem, index) => {
			if (this.semestresGroup.get(sem)?.value) {
				semestresSeleccionados.push((index + 1).toString());
			}
		});

		if (semestresSeleccionados.length === 0) {
			this.showAlert('Debe seleccionar al menos un semestre', 'warning');
			return;
		}

		// Obtener cuotas seleccionadas (hardcodeadas 1-5)
		const cuotasSeleccionadas: number[] = [];
		[1, 2, 3, 4, 5].forEach(numeroCuota => {
			if (this.cuotasGroup.get(`cuota_${numeroCuota}`)?.value) {
				cuotasSeleccionadas.push(numeroCuota);
			}
		});

		if (cuotasSeleccionadas.length === 0) {
			this.showAlert('Debe seleccionar al menos una cuota', 'warning');
			return;
		}

		const baseData = this.moraForm.value;

		// Primero buscar o crear el registro de datos_mora por gestión
		this.moraService.findOrCreateByGestion(baseData.gestion).subscribe({
			next: (resMora) => {
				if (!resMora.success || !resMora.data) {
					this.showAlert('Error al obtener datos de mora para la gestión', 'error');
					return;
				}

				const idDatosMora = resMora.data.id_datos_mora;

				// Crear una configuración por cada combinación de semestre y cuota
				const configuraciones: any[] = [];
				semestresSeleccionados.forEach(semestre => {
					cuotasSeleccionadas.forEach(numeroCuota => {
						configuraciones.push({
							id_datos_mora: idDatosMora,
							semestre: semestre,
							cod_pensum: baseData.pensum,
							cuota: numeroCuota,
							monto: baseData.monto,
							fecha_inicio: baseData.fecha_inicio,
							fecha_fin: baseData.fecha_fin || null,
							activo: baseData.activo
						});
					});
				});

				// Enviar todas las configuraciones
				let completadas = 0;
				let errores = 0;

				configuraciones.forEach(config => {
					this.moraService.createDetalle(config).subscribe({
						next: (res) => {
							completadas++;
							if (completadas + errores === configuraciones.length) {
								this.loadConfiguraciones();
								this.closeModals();
								if (errores === 0) {
									this.showAlert(`${completadas} configuración(es) creada(s) exitosamente`, 'success');
								} else {
									this.showAlert(`${completadas} creadas, ${errores} con error`, 'warning');
								}
							}
						},
						error: (err) => {
							errores++;
							console.error('Mora create:', err);
							if (completadas + errores === configuraciones.length) {
								this.loadConfiguraciones();
								this.closeModals();
								this.showAlert(`${completadas} creadas, ${errores} con error`, 'warning');
							}
						}
					});
				});
			},
			error: (err) => {
				console.error('Error al obtener datos de mora:', err);
				this.showAlert('Error al obtener datos de mora para la gestión', 'error');
			}
		});
	}

	// Toggle estado
	toggleMora(mora: DatosMoraDetalle): void {
		if (!mora.id_datos_mora_detalle) return;
		this.moraService.toggleStatusDetalle(mora.id_datos_mora_detalle).subscribe({
			next: (res) => {
				if (res.success) {
					this.loadConfiguraciones();
					this.showAlert('Estado actualizado exitosamente', 'success');
				}
			},
			error: (err) => {
				console.error('Mora toggle:', err);
				this.showAlert('No se pudo cambiar el estado', 'error');
			}
		});
	}

	// Alertas
	private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
		this.alertMessage = message;
		this.alertType = type;
		setTimeout(() => (this.alertMessage = ''), 4000);
	}
}
