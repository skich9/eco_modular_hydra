import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { CarreraService } from '../../../services/carrera.service';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { CostoMateriaService } from '../../../services/costo-materia.service';

@Component({
  selector: 'app-costos-creditos-config',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  templateUrl: './costos-creditos-config.component.html',
  styleUrls: ['./costos-creditos-config.component.scss']
})
export class CostosCreditosConfigComponent implements OnInit {
  form: FormGroup;
  carreras: any[] = [];
  pensums: Array<{ cod_pensum: string; nombre?: string; descripcion?: string }> = [];
  gestiones: any[] = [];
  loading = false;
  // UI: pestañas por semestre
  semTabs: number[] = [1,2,3,4,5,6];
  activeSem: number = 1;
  creditRowsMap: Record<number, Array<{ tipo: string; valor_credito: number; turno: 'MANANA'|'TARDE'|'NOCHE' }>> = {
    1: [], 2: [], 3: [], 4: [], 5: [], 6: []
  };

  constructor(
    private fb: FormBuilder,
    private carreraService: CarreraService,
    private cobrosService: CobrosService,
    private auth: AuthService,
    private costoMateriaService: CostoMateriaService,
  ) {
    this.form = this.fb.group({
      gestion: ['', Validators.required],
      carrera: ['', Validators.required],
      pensum: ['', Validators.required],
      valor_credito: ['', [Validators.required, Validators.min(0.01)]],
    });
  }

  ngOnInit(): void {
    this.loadGestiones();
    this.loadCarreras();

    this.form.get('carrera')?.valueChanges.subscribe(() => this.onCarreraChange());
  }

  selectSem(n: number): void { this.activeSem = n; }

  getRowsForActive(): Array<{ tipo: string; valor_credito: number; turno: string }> {
    return this.creditRowsMap[this.activeSem] || [];
  }

  semLabel(n: number): string {
    switch (n) {
      case 1: return '1er Semestre';
      case 2: return '2do Semestre';
      case 3: return '3er Semestre';
      case 4: return '4to Semestre';
      case 5: return '5to Semestre';
      case 6: return '6to Semestre';
      default: return String(n);
    }
  }

  private normalizePensum(p: any): { cod_pensum: string; nombre?: string; descripcion?: string } {
    const cod = p?.cod_pensum || p?.codigo_pensum || p?.codigo || p?.cod || p?.id || p?.pensum;
    return {
      cod_pensum: cod,
      nombre: p?.nombre || p?.nombre_pensum || p?.titulo || p?.descripcion,
      descripcion: p?.descripcion || p?.detalle || p?.observacion
    };
  }

  loadGestiones(): void {
    this.cobrosService.getGestionesActivas().subscribe({
      next: (res) => { this.gestiones = res?.data || []; },
      error: () => { this.gestiones = []; }
    });
  }

  loadCarreras(): void {
    this.loading = true;
    this.carreraService.getAll().subscribe({
      next: (res: any) => { this.carreras = res?.data || []; },
      error: () => { this.carreras = []; },
      complete: () => { this.loading = false; }
    });
  }

  onCarreraChange(): void {
    const codigo = this.form.get('carrera')?.value;
    this.form.patchValue({ pensum: '' });
    this.pensums = [];
    if (!codigo) return;

    this.cobrosService.getPensumsByCarrera(codigo).subscribe({
      next: (res) => {
        const raw = (res?.data || []) as any[];
        this.pensums = raw.map(p => this.normalizePensum(p)).filter(p => !!p?.cod_pensum);
      },
      error: () => { this.pensums = []; }
    });
  }

  // Generar costos por crédito para todos los semestres del pensum seleccionado
  generarTodosSemestres(): void {
    const cod_pensum: string = this.form.get('pensum')?.value;
    const gestion: string = this.form.get('gestion')?.value;
    const valor = Number(this.form.get('valor_credito')?.value || 0);
    if (!cod_pensum || !gestion) { alert('Seleccione Gestión, Carrera y Pensum.'); return; }
    if (!(valor > 0)) { alert('Ingrese un valor por crédito válido (> 0).'); return; }
    const currentUser = this.auth.getCurrentUser();
    const id_usuario = currentUser?.id_usuario;
    if (!id_usuario) { alert('No se pudo identificar el usuario.'); return; }
    this.costoMateriaService.generateByPensumGestion({ cod_pensum, gestion, valor_credito: valor, id_usuario }).subscribe({
      next: () => alert('Costos por crédito generados/actualizados para todos los semestres.'),
      error: () => alert('No se pudieron generar los costos por crédito.')
    });
  }

  // Generar costos por crédito solo para el semestre activo (usa nivel_materia)
  generarSoloSemestreActivo(): void {
    const cod_pensum: string = this.form.get('pensum')?.value;
    const gestion: string = this.form.get('gestion')?.value;
    const valor = Number(this.form.get('valor_credito')?.value || 0);
    if (!cod_pensum || !gestion) { alert('Seleccione Gestión, Carrera y Pensum.'); return; }
    if (!(valor > 0)) { alert('Ingrese un valor por crédito válido (> 0).'); return; }
    const currentUser = this.auth.getCurrentUser();
    const id_usuario = currentUser?.id_usuario;
    if (!id_usuario) { alert('No se pudo identificar el usuario.'); return; }
    this.costoMateriaService.generateByPensumGestion({ cod_pensum, gestion, valor_credito: valor, id_usuario, semestre: String(this.activeSem) }).subscribe({
      next: () => alert(`Costos por crédito generados/actualizados para ${this.semLabel(this.activeSem)}.`),
      error: () => alert('No se pudieron generar los costos por crédito para el semestre activo.')
    });
  }
}
