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
	filterCuota = '';
	filterSemestre = '';
	filterPensum = '';

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
		const currentYear = new Date().getFullYear();
		const today = new Date().toISOString().split('T')[0];

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

		// Grupo de cuotas (checkboxes y fechas inicio)
		this.cuotasGroup = this.fb.group({
			marcarTodasCuotas: [false],
			cuota_1: [false],
			fecha_1: [today],
			cuota_2: [false],
			fecha_2: [today],
			cuota_3: [false],
			fecha_3: [today],
			cuota_4: [false],
			fecha_4: [today],
			cuota_5: [false],
			fecha_5: [today]
		});

		this.moraForm = this.fb.group({
			id_datos_mora_detalle: [null],
			gestion: [currentYear.toString(), Validators.required],
			carrera: ['', Validators.required],
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
			next: (res: any) => {
				// Gestiones activas
				if (res.gestiones.success) {
					this.gestiones = res.gestiones.data || [];
					// Ordenar descendente: Año primero, luego periodo (X/YYYY)
					this.gestiones.sort((a: any, b: any) => {
						const [pA, yA] = a.gestion.split('/').map(Number);
						const [pB, yB] = b.gestion.split('/').map(Number);
						if (yB !== yA) return yB - yA;
						return pB - pA;
					});
				}

				// Carreras
				if (res.carreras.success) {
					this.carreras = res.carreras.data;
				}

				this.loading = false;
			},
			error: (err: any) => {
				console.error('Error precargando datos:', err);
				this.loading = false;
			}
		});
	}

	onCarreraChange(): void {
		const carreraCode = this.moraForm.get('carrera')?.value;
		this.pensums = [];

		if (carreraCode) {
			this.loading = true;
			this.carreraService.getPensums(carreraCode).subscribe({
				next: (res: any) => {
					if (res.success) {
						this.pensums = res.data || [];
						console.log(`Pensums cargados para ${carreraCode}:`, this.pensums);
					}
					this.loading = false;
				},
				error: (err: any) => {
					console.error('Error cargando pensums:', err);
					this.loading = false;
				}
			});
		}
	}

	setupCheckboxListeners(): void {
		// Listener para "Marcar Todos Semestres"
		this.semestresGroup.get('marcarTodosSemestres')?.valueChanges.subscribe((checked: boolean) => {
			['sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6'].forEach(sem => {
				this.semestresGroup.get(sem)?.setValue(checked, { emitEvent: false });
			});
		});

		// Listener para "Marcar Todas Cuotas" (hardcodeadas 1-5)
		this.cuotasGroup.get('marcarTodasCuotas')?.valueChanges.subscribe((checked: boolean) => {
			['cuota_1', 'cuota_2', 'cuota_3', 'cuota_4', 'cuota_5'].forEach(cuota => {
				this.cuotasGroup.get(cuota)?.setValue(checked, { emitEvent: false });
			});
		});
	}

	loadConfiguraciones(): void {
		this.loading = true;
		this.moraService.getAllDetalles().subscribe({
			next: (res: any) => {
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
		let filtered = this.configuraciones;

		// Filtro de texto general
		const t = (this.searchText || '').toLowerCase().trim();
		if (t) {
			filtered = filtered.filter(c =>
				(c.semestre && c.semestre.toLowerCase().includes(t)) ||
				(c.cuota && c.cuota.toString().includes(t)) ||
				(c.cod_pensum && c.cod_pensum.toLowerCase().includes(t)) ||
				(c.pensum?.carrera?.nombre && c.pensum.carrera.nombre.toLowerCase().includes(t))
			);
		}

		// Filtro por cuota específico
		if (this.filterCuota) {
			filtered = filtered.filter(c => c.cuota?.toString() === this.filterCuota);
		}

		// Filtro por semestre específico
		if (this.filterSemestre) {
			filtered = filtered.filter(c => c.semestre === this.filterSemestre);
		}

		// Filtro por pensum específico
		if (this.filterPensum) {
			const fp = this.filterPensum.toLowerCase().trim();
			filtered = filtered.filter(c => c.cod_pensum?.toLowerCase().includes(fp));
		}

		return filtered;
	}

	resetFilters(): void {
		this.searchText = '';
		this.filterCuota = '';
		this.filterSemestre = '';
		this.filterPensum = '';
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
			fecha_1: today,
			cuota_2: false,
			fecha_2: today,
			cuota_3: false,
			fecha_3: today,
			cuota_4: false,
			fecha_4: today,
			cuota_5: false,
			fecha_5: today
		});

		this.showMoraModal = true;
	}

	openEditMora(mora: DatosMoraDetalle): void {
		this.editingMora = mora;
		// En modo edición solo cargar fecha_fin y monto
		this.moraForm.patchValue({
			fecha_fin: mora.fecha_fin || '',
			monto: mora.monto
		});
		this.showMoraModal = true;
	}

	closeModals(): void {
		this.showMoraModal = false;
		this.editingMora = null;
	}

	// Guardar
	saveMora(): void {
		// Modo edición: solo actualizar fecha_fin y monto
		if (this.editingMora) {
			const updateData = {
				fecha_fin: this.moraForm.value.fecha_fin || null,
				monto: this.moraForm.value.monto
			};

			this.moraService.updateDetalle(this.editingMora.id_datos_mora_detalle!, updateData as any).subscribe({
				next: (res: any) => {
					if (res.success) {
						this.loadConfiguraciones();
						this.closeModals();
						this.showAlert('Configuración actualizada exitosamente', 'success');
					}
				},
				error: (err: any) => {
					console.error('Error al actualizar:', err);
					this.showAlert('Error al actualizar la configuración', 'error');
				}
			});
			return;
		}

		// Modo creación
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
			next: (resMora: any) => {
				if (!resMora.success || !resMora.data) {
					this.showAlert('Error al obtener datos de mora para la gestión', 'error');
					return;
				}

				const idDatosMora = resMora.data.id_datos_mora;

				// Filtrar solo los pensums activos (muy flexible para debug)
				const pensumsActivos = this.pensums.filter(p => {
					const val = p.activo !== undefined ? p.activo : p.estado;
					// Consideramos activo si es 1, true, o si es algo distinto de 0/false/null/undefined
					return val == 1 || val === true || (val !== 0 && val !== false && val != null);
				});

				if (pensumsActivos.length === 0) {
					this.showAlert('La carrera seleccionada no tiene pensums activos', 'warning');
					return;
				}

				// Obtener todos los pensums activos (ahora es obligatorio tener al menos uno)
				const pensumsAConfigurar = pensumsActivos;

				// Crear una configuración por cada combinación de pensum, semestre y cuota
				const configuraciones: any[] = [];
				pensumsAConfigurar.forEach(p => {
					semestresSeleccionados.forEach(semestre => {
						cuotasSeleccionadas.forEach(numeroCuota => {
							// Obtener la fecha específica para esta cuota
							const fechaInicioCuota = this.cuotasGroup.get(`fecha_${numeroCuota}`)?.value || baseData.fecha_inicio;

							configuraciones.push({
								id_datos_mora: idDatosMora,
								semestre: semestre,
								cod_pensum: p.cod_pensum,
								cuota: numeroCuota,
								monto: baseData.monto,
								fecha_inicio: fechaInicioCuota,
								fecha_fin: baseData.fecha_fin || null,
								activo: baseData.activo
							});
						});
					});
				});

				// Enviar todas las configuraciones
				let completadas = 0;
				let errores = 0;

				if (configuraciones.length === 0) {
					this.showAlert('No hay configuraciones para crear', 'warning');
					return;
				}

				configuraciones.forEach(config => {
					this.moraService.createDetalle(config).subscribe({
						next: (res: any) => {
							completadas++;
							if (completadas + errores === configuraciones.length) {
								this.loadConfiguraciones();
								this.closeModals();
								if (errores === 0) {
									this.showAlert(`${completadas} registro(s) creado(s) exitosamente`, 'success');
								} else {
									this.showAlert(`${completadas} creadas, ${errores} con error`, 'warning');
								}
							}
						},
						error: (err: any) => {
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
			next: (res: any) => {
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
