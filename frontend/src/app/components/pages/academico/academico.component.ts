import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { CarreraService } from '../../../services/carrera.service';
import { MateriaService } from '../../../services/materia.service';
import { Pensum, Materia } from '../../../models/materia.model';

@Component({
  selector: 'app-academico',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule],
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

  loadingPensums = false;
  loadingMaterias = false;
  pensumFilter = '';
  materiaFilter = '';

  private sub: any;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private carreraService: CarreraService,
    private materiaService: MateriaService
  ) {}

  ngOnInit(): void {
    this.sub = this.route.params.subscribe((params: { [key: string]: string }) => {
      this.codigoCarrera = params['codigo'];
      if (!this.codigoCarrera) {
        this.router.navigate(['/dashboard']);
        return;
      }
      this.loadPensums();
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
        this.materias = res.data || [];
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
}
