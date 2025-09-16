import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule, FormsModule } from '@angular/forms';
import { ParametrosEconomicosService } from '../../../services/parametros-economicos.service';
import { ItemsCobroService } from '../../../services/items-cobro.service';
import { SinActividadesService, SinActividad } from '../../../services/sin-actividades.service';
import { ParametroEconomico } from '../../../models/parametro-economico.model';
import { ItemCobro } from '../../../models/item-cobro.model';

@Component({
  selector: 'app-parametros-simple',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  templateUrl: './parametros-simple.component.html',
  styleUrls: ['./parametros-simple.component.scss']
})
export class ParametrosSimpleComponent implements OnInit {
  // Datos
  parametros: ParametroEconomico[] = [];
  items: ItemCobro[] = [];
  actividades: SinActividad[] = [];

  // Búsquedas en tiempo real
  searchPE = '';
  searchIC = '';

  // Formularios
  parametroForm: FormGroup;
  itemForm: FormGroup;

  // Estado UI
  loading = false;
  alertMessage = '';
  alertType: 'success' | 'error' | 'warning' = 'success';
  // Pestañas
  activeTab: 'pe' | 'ic' = 'pe';

  // Modales
  showParamModal = false;
  showItemModal = false;
  showDeleteModal = false;
  deleteType: 'pe' | 'ic' | '' = '';
  deleteTarget: any = null;

  // Edición
  editingParam: ParametroEconomico | null = null;
  editingItem: ItemCobro | null = null;
  originalNombrePE: string | null = null; // para validaciones únicas en backend

  constructor(
    private fb: FormBuilder,
    private peService: ParametrosEconomicosService,
    private icService: ItemsCobroService,
    private sinActService: SinActividadesService
  ) {
    // Formulario Parámetros Económicos
    this.parametroForm = this.fb.group({
      id_parametro_economico: [null],
      nombre: ['', [Validators.required, Validators.maxLength(20)]],
      valor: ['', [Validators.required, Validators.maxLength(255)]],
      descripcion: ['', [Validators.required, Validators.maxLength(255)]],
      estado: [true]
    });

    // Formulario Items de Cobro
    this.itemForm = this.fb.group({
      id_item: [null],
      codigo_producto_impuesto: [null],
      codigo_producto_interno: ['', [Validators.required, Validators.maxLength(15)]],
      unidad_medida: [null, Validators.required],
      nombre_servicio: ['', [Validators.required, Validators.maxLength(100)]],
      nro_creditos: [0, [Validators.required, Validators.min(0)]],
      costo: [null, Validators.min(0)],
      facturado: [false],
      actividad_economica: ['', [Validators.required, Validators.maxLength(25)]],
      // descripcion es TEXT en backend; no limitamos aquí
      descripcion: [''],
      tipo_item: ['', [Validators.required, Validators.maxLength(40)]],
      estado: [true],
      id_parametro_economico: [null, Validators.required]
    });
  }

  // CARGA DE ACTIVIDADES (SIN)
  loadActividades(q: string = ''): void {
    this.sinActService.search(q, 50).subscribe({
      next: (res) => {
        this.actividades = res.data || [];
      },
      error: (err) => {
        console.error('SIN actividades:', err);
      }
    });
  }

  ngOnInit(): void {
    this.loadAll();
    this.loadActividades();
  }

  // CARGA DE DATOS
  loadAll(): void {
    this.loading = true;
    this.loadParametros();
    this.loadItems();
  }

  loadParametros(): void {
    this.peService.getAll().subscribe({
      next: (res: { success: boolean; data: ParametroEconomico[] }) => {
        if (res.success) {
          this.parametros = res.data;
        } else {
          this.showAlert('Error al cargar parámetros económicos', 'error');
        }
      },
      error: (err: any) => {
        console.error('Error PE:', err);
        this.showAlert('No se pudo cargar parámetros económicos', 'error');
      }
    });
  }

  loadItems(): void {
    this.icService.getAll().subscribe({
      next: (res: { success: boolean; data: ItemCobro[] }) => {
        if (res.success) {
          this.items = res.data;
        } else {
          this.showAlert('Error al cargar items de cobro', 'error');
        }
        this.loading = false;
      },
      error: (err: any) => {
        console.error('Error IC:', err);
        this.showAlert('No se pudo cargar items de cobro', 'error');
        this.loading = false;
      }
    });
  }

  // PESTAÑAS
  setTab(tab: 'pe' | 'ic'): void {
    this.activeTab = tab;
  }

  // FILTROS EN TIEMPO REAL
  get filteredParametros(): ParametroEconomico[] {
    const t = (this.searchPE || '').toLowerCase().trim();
    if (!t) return this.parametros;
    return this.parametros.filter(p =>
      p.nombre.toLowerCase().includes(t) ||
      (p.descripcion && p.descripcion.toLowerCase().includes(t))
    );
  }

  get filteredItems(): ItemCobro[] {
    const t = (this.searchIC || '').toLowerCase().trim();
    if (!t) return this.items;
    return this.items.filter(i =>
      i.nombre_servicio.toLowerCase().includes(t) ||
      i.codigo_producto_interno.toLowerCase().includes(t) ||
      (i.descripcion && i.descripcion.toLowerCase().includes(t))
    );
  }

  // MODALES
  openNewParametro(): void {
    this.editingParam = null;
    this.originalNombrePE = null;
    this.parametroForm.reset({ estado: true });
    this.showParamModal = true;
  }

  openEditParametro(p: ParametroEconomico): void {
    this.editingParam = p;
    this.originalNombrePE = p.nombre;
    this.parametroForm.patchValue(p);
    this.showParamModal = true;
  }

  openNewItem(): void {
    this.editingItem = null;
    this.itemForm.reset({ estado: true, facturado: false, nro_creditos: 0 });
    this.showItemModal = true;
  }

  openEditItem(i: ItemCobro): void {
    this.editingItem = i;
    this.itemForm.patchValue(i);
    this.showItemModal = true;
  }

  closeModals(): void {
    this.showParamModal = false;
    this.showItemModal = false;
    this.editingParam = null;
    this.editingItem = null;
    this.originalNombrePE = null;
  }

  // GUARDAR / ACTUALIZAR
  saveParametro(): void {
    if (!this.parametroForm.valid) return;
    const data = this.parametroForm.value as ParametroEconomico;
    if (this.editingParam) {
      this.peService.update(this.editingParam.id_parametro_economico, data, this.originalNombrePE || this.editingParam.nombre).subscribe({
        next: (res: { success: boolean; data: ParametroEconomico; message: string }) => {
          if (res.success) {
            this.loadParametros();
            this.closeModals();
            this.showAlert('Parámetro actualizado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Update PE:', err);
          this.showAlert('No se pudo actualizar el parámetro', 'error');
        }
      });
    } else {
      this.peService.create(data).subscribe({
        next: (res: { success: boolean; data: ParametroEconomico; message: string }) => {
          if (res.success) {
            this.loadParametros();
            this.closeModals();
            this.showAlert('Parámetro creado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Create PE:', err);
          this.showAlert('No se pudo crear el parámetro', 'error');
        }
      });
    }
  }

  saveItem(): void {
    if (!this.itemForm.valid) return;
    const data = this.itemForm.value as ItemCobro;
    if (this.editingItem) {
      this.icService.update(this.editingItem.id_item, data).subscribe({
        next: (res: { success: boolean; data: ItemCobro; message: string }) => {
          if (res.success) {
            this.loadItems();
            this.closeModals();
            this.showAlert('Item actualizado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Update IC:', err);
          if (err?.status === 422 && err?.error?.errors) {
            const msg = this.formatBackendErrors(err.error.errors);
            this.applyBackendErrorsToForm(err.error.errors);
            this.showAlert(`Errores de validación: ${msg}`, 'error');
          } else {
            this.showAlert('No se pudo actualizar el item', 'error');
          }
        }
      });
    } else {
      this.icService.create(data).subscribe({
        next: (res: { success: boolean; data: ItemCobro; message: string }) => {
          if (res.success) {
            this.loadItems();
            this.closeModals();
            this.showAlert('Item creado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Create IC:', err);
          if (err?.status === 422 && err?.error?.errors) {
            const msg = this.formatBackendErrors(err.error.errors);
            this.applyBackendErrorsToForm(err.error.errors);
            this.showAlert(`Errores de validación: ${msg}`, 'error');
          } else {
            this.showAlert('No se pudo crear el item', 'error');
          }
        }
      });
    }
  }

  // ELIMINAR
  confirmDelete(target: any, type: 'pe' | 'ic'): void {
    this.deleteTarget = target;
    this.deleteType = type;
    this.showDeleteModal = true;
  }

  cancelDelete(): void {
    this.showDeleteModal = false;
    this.deleteTarget = null;
    this.deleteType = '';
  }

  doDelete(): void {
    if (this.deleteType === 'pe') {
      const p = this.deleteTarget as ParametroEconomico;
      this.peService.delete(p.id_parametro_economico, p.nombre).subscribe({
        next: (res: { success: boolean; message: string }) => {
          if (res.success) {
            this.loadParametros();
            this.cancelDelete();
            this.showAlert('Parámetro eliminado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Delete PE:', err);
          if (err?.status === 409 && err?.error?.error_type === 'foreign_key_constraint') {
            const msg: string = err?.error?.message || 'No se puede eliminar este parámetro económico porque tiene dependencias.';
            this.cancelDelete();
            this.showAlert(msg, 'warning');
          } else if (err?.status === 422 && err?.error?.errors) {
            const msg = this.formatBackendErrors(err.error.errors);
            this.cancelDelete();
            this.showAlert(`Errores de validación: ${msg}`, 'error');
          } else {
            this.cancelDelete();
            this.showAlert('No se pudo eliminar el parámetro', 'error');
          }
        }
      });
    }
    if (this.deleteType === 'ic') {
      const i = this.deleteTarget as ItemCobro;
      this.icService.delete(i.id_item).subscribe({
        next: (res: { success: boolean; message: string }) => {
          if (res.success) {
            this.loadItems();
            this.cancelDelete();
            this.showAlert('Item eliminado', 'success');
          }
        },
        error: (err: any) => {
          console.error('Delete IC:', err);
          this.showAlert('No se pudo eliminar el item', 'error');
        }
      });
    }
  }

  // TOGGLE ESTADO
  toggleParametro(p: ParametroEconomico): void {
    this.peService.toggleStatus(p.id_parametro_economico, p.nombre).subscribe({
      next: (res: { success: boolean; data: ParametroEconomico; message: string }) => {
        if (res.success) {
          this.loadParametros();
          this.showAlert('Estado actualizado', 'success');
        }
      },
      error: (err: any) => {
        console.error('Toggle PE:', err);
        this.showAlert('No se pudo cambiar el estado', 'error');
      }
    });
  }

  toggleItem(i: ItemCobro): void {
    this.icService.toggleStatus(i.id_item).subscribe({
      next: (res: { success: boolean; data: ItemCobro; message: string }) => {
        if (res.success) {
          this.loadItems();
          this.showAlert('Estado actualizado', 'success');
        }
      },
      error: (err: any) => {
        console.error('Toggle IC:', err);
        this.showAlert('No se pudo cambiar el estado', 'error');
      }
    });
  }

  // Alertas
  private showAlert(message: string, type: 'success' | 'error' | 'warning'): void {
    this.alertMessage = message;
    this.alertType = type;
    // Asegurar visibilidad: llevar la vista hacia el contenedor de alertas
    try {
      // Ejecutar tras el ciclo de render
      setTimeout(() => {
        const el = document.querySelector('.alerts');
        if (el && 'scrollIntoView' in el) {
          (el as any).scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
      }, 0);
    } catch {}
    setTimeout(() => (this.alertMessage = ''), 4000);
  }

  private formatBackendErrors(errors: any): string {
    try {
      return Object.keys(errors)
        .map(k => `${k}: ${Array.isArray(errors[k]) ? errors[k].join(', ') : errors[k]}`)
        .join(' | ');
    } catch {
      return 'Error de validación';
    }
  }

  private applyBackendErrorsToForm(errors: any): void {
    if (!errors) return;
    Object.keys(errors).forEach(key => {
      const ctrl = this.itemForm.get(key);
      if (ctrl) {
        const msg = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
        ctrl.setErrors({ ...(ctrl.errors || {}), backend: true, message: msg });
        ctrl.markAsTouched();
      }
    });
  }
}
