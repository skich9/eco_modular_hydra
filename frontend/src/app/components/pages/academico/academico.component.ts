import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { CarreraService } from '../../../services/carrera.service';
import { MateriaService } from '../../../services/materia.service';
import { Pensum, Materia } from '../../../models/materia.model';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Gestion } from '../../../models/gestion.model';
import { GestionService } from '../../../services/gestion.service';
import { CostoMateriaService } from '../../../services/costo-materia.service';
// Eliminado: parámetros económicos ya no se asocian directamente a materia

@Component({
  selector: 'app-academico',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule, ReactiveFormsModule],
  templateUrl: './academico.component.html',
  styleUrls: ['./academico.component.scss']
})
export class AcademicoComponent implements OnInit, OnDestroy {
  codigoCarrera = '';
  pensums: Pensum[] = [];
  filteredPensums: Pensum[] = [];
  materias: Materia[] = [];
  filteredMaterias: Materia[] = [];
  selectedPensum: Pensum | null = null;
  gestionActual: Gestion | null = null;
  costosPorSigla: Record<string, number> = {};

  // Modal y formulario
  modalOpen = false;
  modalMode: 'create' | 'edit' = 'create';
  form!: FormGroup;
  editingKeys: { sigla: string; pensum: string } | null = null;

  loadingPensums = false;
  loadingMaterias = false;
  loadingAux = false; // para acciones de guardar/eliminar
  pensumFilter = '';
  materiaFilter = '';

  private sub: any;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private carreraService: CarreraService,
    private materiaService: MateriaService,
    private fb: FormBuilder,
    private gestionService: GestionService,
    private costoMateriaService: CostoMateriaService,
  ) {}

  ngOnInit(): void {
    this.sub = this.route.params.subscribe((params: { [key: string]: string }) => {
      this.codigoCarrera = params['codigo'];
      if (!this.codigoCarrera) {
        this.router.navigate(['/dashboard']);
        return;
      }
      this.loadPensums();
      this.loadGestionYCostos();
      // Ya no es necesario cargar parámetros económicos para el formulario de materia
    });
  }

  ngOnDestroy(): void {
    if (this.sub) {
      this.sub.unsubscribe();
    }
  }

  loadPensums(): void {
    this.loadingPensums = true;
    this.carreraService.getPensums(this.codigoCarrera).subscribe({
      next: (res: { success: boolean; data: Pensum[] }) => {
        this.pensums = res.data || [];
        this.applyPensumFilter();
        // Seleccionar el primer pensum por defecto
        if (this.pensums.length > 0) {
          this.onSelectPensum(this.pensums[0]);
        } else {
          this.selectedPensum = null;
          this.materias = [];
          this.filteredMaterias = [];
        }
      },
      error: (err: any) => {
        console.error('Error al cargar pensums:', err);
      },
      complete: () => {
        this.loadingPensums = false;
      }
    });
  }

  onSelectPensum(p: Pensum): void {
    this.selectedPensum = p;
    this.loadMaterias(p.cod_pensum);
  }

  loadMaterias(codPensum: string): void {
    this.loadingMaterias = true;
    this.materiaService.getByPensum(codPensum).subscribe({
      next: (res: { success: boolean; data: Materia[] }) => {
        const base = res.data || [];
        // Merge costos si existen
        this.materias = base.map(m => ({
          ...m,
          monto_materia: this.costosPorSigla[m.sigla_materia]
        }));
        this.applyMateriaFilter();
      },
      error: (err: any) => {
        console.error('Error al cargar materias:', err);
      },
      complete: () => {
        this.loadingMaterias = false;
      }
    });
  }

  applyPensumFilter(): void {
    const term = this.pensumFilter.trim().toLowerCase();
    if (!term) {
      this.filteredPensums = [...this.pensums];
      return;
    }
    this.filteredPensums = this.pensums.filter(p =>
      (p.nombre || '').toLowerCase().includes(term) ||
      (p.cod_pensum || '').toLowerCase().includes(term)
    );
  }

  applyMateriaFilter(): void {
    const term = this.materiaFilter.trim().toLowerCase();
    if (!term) {
      this.filteredMaterias = [...this.materias];
      return;
    }
    this.filteredMaterias = this.materias.filter(m =>
      (m.nombre_materia || '').toLowerCase().includes(term) ||
      (m.sigla_materia || '').toLowerCase().includes(term)
    );
  }

  // Cargar gestión actual y costos por materia
  private loadGestionYCostos(): void {
    this.gestionService.getActual().subscribe({
      next: (res) => {
        this.gestionActual = res.data;
        if (!this.gestionActual?.gestion) return;
        this.costoMateriaService.getByGestion(this.gestionActual.gestion).subscribe({
          next: (cres) => {
            const mapa: Record<string, number> = {};
            (cres.data || []).forEach(c => {
              if (c?.sigla_materia != null && c?.monto_materia != null) {
                mapa[c.sigla_materia] = Number(c.monto_materia);
              }
            });
            this.costosPorSigla = mapa;
            // Si ya hay materias cargadas, refrescar merge
            if (this.materias.length) {
              this.materias = this.materias.map(m => ({ ...m, monto_materia: this.costosPorSigla[m.sigla_materia] }));
              this.applyMateriaFilter();
            }
          },
          error: (err) => console.error('Error al cargar costos por gestión:', err)
        });
      },
      error: (err) => console.error('Error al obtener gestión actual:', err)
    });
  }

  // Eliminado: carga de parámetros económicos

  // Modal form helpers
  private buildForm(m?: Materia): void {
    this.form = this.fb.group({
      sigla_materia: [m?.sigla_materia || '', [Validators.required, Validators.maxLength(10)]],
      cod_pensum: [m?.cod_pensum || this.selectedPensum?.cod_pensum || '', [Validators.required]],
      nombre_materia: [m?.nombre_materia || '', [Validators.required, Validators.maxLength(100)]],
      nombre_material_oficial: [m?.nombre_material_oficial || '', [Validators.required, Validators.maxLength(100)]],
      nro_creditos: [m?.nro_creditos ?? 1, [Validators.required, Validators.min(1)]],
      orden: [m?.orden ?? 1, [Validators.required, Validators.min(1)]],
      descripcion: [m?.descripcion || ''],
      estado: [m?.estado ?? true]
    });
  }

  openCreateModal(): void {
    if (!this.selectedPensum) return;
    this.modalMode = 'create';
    this.editingKeys = null;
    this.buildForm();
    this.modalOpen = true;
  }

  openEditModal(m: Materia): void {
    this.modalMode = 'edit';
    this.editingKeys = { sigla: m.sigla_materia, pensum: m.cod_pensum };
    this.buildForm(m);
    this.modalOpen = true;
  }

  closeModal(): void {
    this.modalOpen = false;
  }

  // Cerrar modal al hacer click fuera del cuadro
  onModalContainerClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (target && target.classList && target.classList.contains('custom-modal')) {
      this.closeModal();
    }
  }

  saveMateria(): void {
    if (!this.form) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const payload = this.form.getRawValue() as Materia;
    this.loadingAux = true;
    if (this.modalMode === 'create') {
      this.materiaService.create(payload).subscribe({
        next: () => {
          this.closeModal();
          if (this.selectedPensum) this.loadMaterias(this.selectedPensum.cod_pensum);
        },
        error: (err) => {
          console.error('Error al crear materia:', err);
          this.loadingAux = false; // re-habilitar botones al fallar
        },
        complete: () => (this.loadingAux = false)
      });
    } else if (this.modalMode === 'edit' && this.editingKeys) {
      this.materiaService.update(this.editingKeys.sigla, this.editingKeys.pensum, payload).subscribe({
        next: () => {
          this.closeModal();
          if (this.selectedPensum) this.loadMaterias(this.selectedPensum.cod_pensum);
        },
        error: (err) => {
          console.error('Error al actualizar materia:', err);
          this.loadingAux = false; // re-habilitar botones al fallar
        },
        complete: () => (this.loadingAux = false)
      });
    }
  }

  deleteMateria(m: Materia): void {
    if (!m) return;
    const ok = window.confirm(`¿Eliminar la materia ${m.sigla_materia} - ${m.nombre_materia}?`);
    if (!ok) return;
    this.loadingAux = true;
    this.materiaService.delete(m.sigla_materia, m.cod_pensum).subscribe({
      next: () => {
        if (this.selectedPensum) this.loadMaterias(this.selectedPensum.cod_pensum);
      },
      error: (err) => console.error('Error al eliminar materia:', err),
      complete: () => (this.loadingAux = false)
    });
  }
}
