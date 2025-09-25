import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { CarreraService } from '../../../services/carrera.service';
import { CobrosService } from '../../../services/cobros.service';
import { AuthService } from '../../../services/auth.service';
import { CostoMateriaService } from '../../../services/costo-materia.service';
import { MateriaService } from '../../../services/materia.service';
import { Materia } from '../../../models/materia.model';
import { ParametrosEconomicosService } from '../../../services/parametros-economicos.service';
import { ParametroEconomico } from '../../../models/parametro-economico.model';

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
  // Materias agrupadas por semestre (nivel_materia)
  materiasBySem: Record<number, Materia[]> = {
    1: [], 2: [], 3: [], 4: [], 5: [], 6: []
  };
  // Habilitación de edición por materia (checkbox) y mapa de créditos editables
  materiaEditEnabled: Record<string, boolean> = {};
  materiaCreditosMap: Record<string, number> = {};
  // Mapa de montos por materia según costo_materia (gestion actual)
  costoMateriaMontoMap: Record<string, number> = {};
  private costoMateriaAllByGestion: any[] = [];
  // Valor unitario del crédito desde parámetros económicos (nombre = 'credito')
  private creditUnit: number | null = null;
  private creditUnitLoaded = false;
  private creditUnitAlertShown = false;
  // Tabla resumen inferior
  activeCarreraTab: string | null = null;
  // Modal de edición de créditos
  editCreditOpen: boolean = false;
  editCreditForm!: FormGroup;
  selectedMateriaForEdit: Materia | null = null;

  constructor(
    private fb: FormBuilder,
    private carreraService: CarreraService,
    private cobrosService: CobrosService,
    private auth: AuthService,
    private costoMateriaService: CostoMateriaService,
    private materiaService: MateriaService,
    private parametrosEconomicosService: ParametrosEconomicosService,
  ) {
    this.form = this.fb.group({
      gestion: ['', Validators.required],
      carrera: ['', Validators.required],
      pensum: ['', Validators.required],
      valor_credito: ['', [Validators.required, Validators.min(0.01)]],
    });
    // Form del modal de edición
    this.editCreditForm = this.fb.group({
      creditos: [0, [Validators.required, Validators.min(0)]]
    });
  }

  // Construye el mapa de montos costo_materia para el pensum seleccionado en la gestión actual
  private updateCostoMateriaMapForPensum(): void {
    const pensum = String(this.form.get('pensum')?.value || '');
    const filtered = (this.costoMateriaAllByGestion || []).filter((r: any) => (r?.cod_pensum || '') === pensum);
    const map: Record<string, number> = {};
    for (const r of filtered) {
      const key = `${r.sigla_materia}::${r.cod_pensum}`;
      map[key] = Number(r.monto_materia || 0);
    }
    this.costoMateriaMontoMap = map;
  }

  // ===================== Resumen inferior (UI) =====================
  // Cambiar pestaña de carrera
  selectCarreraTab(code: string): void { this.activeCarreraTab = code; }

  // Filas del resumen para la carrera activa
  getSummaryRowsForActiveCarrera(): Array<{ gestion: string; pensum: string; sigla: string; nombre: string; creditos: number; monto: number }>{
    if (!this.activeCarreraTab) return [];
    // Mostramos datos sólo si la carrera activa coincide con la seleccionada en el formulario
    const selected = String(this.form.get('carrera')?.value || '');
    if (!selected || selected !== this.activeCarreraTab) return [];
    return this.getSummaryRowsForActive();
  }

  // Acción: habilitar edición para una materia desde el resumen
  enableEditFromSummary(sigla: string): void {
    const list = this.getMateriasForActive();
    const m = list.find(x => x.sigla_materia === sigla);
    if (!m) return;
    const k = this.makeKey(m);
    this.materiaEditEnabled[k] = true;
    if (this.materiaCreditosMap[k] == null) {
      this.materiaCreditosMap[k] = Number(m.nro_creditos || 0);
    }
  }

  // Modal: abrir edición de créditos desde el resumen
  openEditCredit(sigla: string): void {
    const list = this.getMateriasForActive();
    const m = list.find(x => x.sigla_materia === sigla);
    if (!m) return;
    this.selectedMateriaForEdit = m;
    this.editCreditForm.reset({ creditos: this.getCreditosValue(m) });
    this.editCreditOpen = true;
  }

  // Modal: cerrar
  closeEditCredit(): void {
    this.editCreditOpen = false;
    this.selectedMateriaForEdit = null;
  }

  // Modal: guardar cambios en créditos (solo local, persistirá con botón Guardar principal)
  saveEditCredit(): void {
    if (!this.editCreditForm.valid || !this.selectedMateriaForEdit) return;
    const val = Number(this.editCreditForm.value.creditos || 0);
    const k = this.makeKey(this.selectedMateriaForEdit);
    this.materiaEditEnabled[k] = true; // marcar para guardar
    this.materiaCreditosMap[k] = val;  // actualizar valor editado
    this.closeEditCredit();
  }

  // Helpers
  private resetEdits(): void {
    // Limpia los checkboxes y los valores editados
    this.materiaEditEnabled = {};
    this.materiaCreditosMap = {};
  }

  // Resumen por semestre bajo el botón Guardar (BASE: costo_materia)
  getSummaryRowsForActive(): Array<{ gestion: string; pensum: string; sigla: string; nombre: string; creditos: number; monto: number }>{
    const rows: Array<{ gestion: string; pensum: string; sigla: string; nombre: string; creditos: number; monto: number }> = [];
    const gestion = String(this.form.get('gestion')?.value || '');
    const pensum = String(this.form.get('pensum')?.value || '');
    if (!gestion || !pensum) return rows;

    // Mapa de materias del semestre activo por sigla
    const materiasActive = this.getMateriasForActive();
    const matBySigla: Record<string, Materia> = {};
    for (const m of materiasActive) { matBySigla[m.sigla_materia] = m; }

    // Filtrar costo_materia por gestión y pensum actual y hacer join con materia del semestre activo
    const base = (this.costoMateriaAllByGestion || []).filter((r: any) => (r?.cod_pensum || '') === pensum);
    for (const r of base) {
      const m = matBySigla[r.sigla_materia];
      if (!m) continue; // mostrar solo las materias del semestre activo
      rows.push({
        gestion,
        pensum,
        sigla: r.sigla_materia,
        nombre: m.nombre_materia,
        creditos: this.getCreditosValue(m),
        monto: Number(r.monto_materia || 0)
      });
    }

    // Ordenar por sigla para consistencia
    rows.sort((a, b) => a.sigla.localeCompare(b.sigla));
    return rows;
  }

  // Guardar: actualiza créditos en materias seleccionadas (checkbox) y upsert en costo_materia
  onSave(): void {
    const cod_pensum: string = this.form.get('pensum')?.value;
    const gestion: string = this.form.get('gestion')?.value;
    if (!cod_pensum || !gestion) { alert('Seleccione Gestión y Pensum.'); return; }

    // Verificar parámetro económico 'credito'
    if (!this.creditUnitLoaded) { this.loadCreditUnit(); alert('Cargando parámetro económico "credito"... intente nuevamente.'); return; }
    if (this.creditUnit == null) { alert('No existe el parámetro económico "credito". No es posible guardar.'); return; }

    const currentUser = this.auth.getCurrentUser();
    const id_usuario = currentUser?.id_usuario;
    if (!id_usuario) { alert('No se pudo identificar el usuario.'); return; }

    const selected: Materia[] = (this.getMateriasForActive() || []).filter(m => this.isEditEnabled(m));
    if (selected.length === 0) { alert('Seleccione al menos una materia (marque la casilla Editar).'); return; }

    // 1) Preparar lote de actualización de créditos en materia
    const creditsItems = selected.map(m => ({
      sigla_materia: m.sigla_materia,
      cod_pensum: m.cod_pensum,
      nro_creditos: this.getCreditosValue(m)
    }));

    // 2) Preparar lote de upsert en costo_materia
    const costoItems = selected.map(m => ({
      cod_pensum: m.cod_pensum,
      sigla_materia: m.sigla_materia,
      valor_credito: Number(this.creditUnit || 0),
      monto_materia: this.getMontoFor(m),
      id_usuario
    }));

    // 3) Ejecutar peticiones en secuencia: primero actualizar créditos, luego upsert costos
    this.materiaService.batchUpdateCredits(creditsItems).subscribe({
      next: () => {
        this.costoMateriaService.batchUpsert(gestion, costoItems).subscribe({
          next: () => {
            alert('Cambios guardados correctamente.');
            // Limpiar estado de edición y recargar materias
            this.resetEdits();
            this.onPensumChange();
          },
          error: () => {
            alert('Se actualizaron los créditos, pero no se pudieron guardar los costos por materia.');
          }
        });
      },
      error: () => {
        alert('No se pudieron actualizar los créditos de las materias.');
      }
    });
  }

  ngOnInit(): void {
    this.loadGestiones();
    this.loadCarreras();
    this.loadCreditUnit();

    this.form.get('carrera')?.valueChanges.subscribe(() => this.onCarreraChange());
    this.form.get('pensum')?.valueChanges.subscribe(() => this.onPensumChange());
    this.form.get('gestion')?.valueChanges.subscribe(() => this.onGestionChange());
  }

  selectSem(n: number): void { this.activeSem = n; }

  getRowsForActive(): Array<{ tipo: string; valor_credito: number; turno: string }> {
    return this.creditRowsMap[this.activeSem] || [];
  }

  getMateriasForActive(): Materia[] {
    return this.materiasBySem[this.activeSem] || [];
  }

  private makeKey(m: Materia): string {
    return `${m.sigla_materia}::${m.cod_pensum}`;
  }

  isEditEnabled(m: Materia): boolean {
    return !!this.materiaEditEnabled[this.makeKey(m)];
  }

  getCreditosValue(m: Materia): number {
    const k = this.makeKey(m);
    return this.materiaCreditosMap[k] ?? Number(m.nro_creditos || 0);
  }

  onToggleEdit(m: Materia, checked: boolean): void {
    const k = this.makeKey(m);
    this.materiaEditEnabled[k] = checked;
    if (checked && this.materiaCreditosMap[k] == null) {
      this.materiaCreditosMap[k] = Number(m.nro_creditos || 0);
    }
  }

  onCreditosInput(m: Materia, ev: Event): void {
    const input = ev.target as HTMLInputElement;
    const val = Number(input.value || 0);
    this.materiaCreditosMap[this.makeKey(m)] = val;
  }

  getMontoFor(m: Materia): number {
    const creditos = this.getCreditosValue(m);
    if (this.creditUnitLoaded) {
      if (this.creditUnit == null) {
        if (!this.creditUnitAlertShown) {
          alert('No existe el parámetro económico "credito". No es posible calcular el monto.');
          this.creditUnitAlertShown = true;
        }
        return 0;
      }
      return Number(((this.creditUnit || 0) * creditos).toFixed(2));
    }
    // Si aún no cargó, intentamos cargar y devolvemos 0 temporalmente
    this.loadCreditUnit();
    return 0;
  }

  getTotalForActive(): number {
    const rows = this.getMateriasForActive();
    let sum = 0;
    for (const m of rows) {
      sum += this.getMontoFor(m);
    }
    return Number(sum.toFixed(2));
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
      next: (res: any) => {
        this.carreras = res?.data || [];
        // Seleccionar pestaña activa por defecto
        const selected = String(this.form.get('carrera')?.value || '');
        this.activeCarreraTab = selected || (this.carreras[0]?.codigo_carrera ?? null);
      },
      error: () => { this.carreras = []; },
      complete: () => { this.loading = false; }
    });
  }

  onCarreraChange(): void {
    const codigo = this.form.get('carrera')?.value;
    this.form.patchValue({ pensum: '' });
    this.pensums = [];
    this.activeCarreraTab = codigo || null;
    if (!codigo) return;

    this.cobrosService.getPensumsByCarrera(codigo).subscribe({
      next: (res) => {
        const raw = (res?.data || []) as any[];
        this.pensums = raw.map(p => this.normalizePensum(p)).filter(p => !!p?.cod_pensum);
      },
      error: () => { this.pensums = []; }
    });
  }

  onPensumChange(): void {
    const codPensum: string = this.form.get('pensum')?.value;
    // Limpiar vista
    this.materiasBySem = { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [] };
    this.materiaEditEnabled = {};
    this.materiaCreditosMap = {};
    if (!codPensum) return;
    this.loading = true;
    this.materiaService.getByPensum(codPensum).subscribe({
      next: (res) => {
        const list: Materia[] = (res?.data || []) as Materia[];
        const buckets: Record<number, Materia[]> = { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [] };
        for (const m of list) {
          if (m && (m as any).estado === true) { // solo materias activas
            const sem = Math.max(1, Math.min(6, parseInt(String(m.nivel_materia || '1'), 10) || 1));
            buckets[sem].push(m);
            // No habilitar edición por defecto
            const k = this.makeKey(m);
            this.materiaEditEnabled[k] = false;
          }
        }
        // Ordenar por 'orden' asc y luego por nombre
        for (const k of [1,2,3,4,5,6]) {
          buckets[k].sort((a, b) => {
            const ao = (a.orden ?? 0), bo = (b.orden ?? 0);
            if (ao !== bo) return ao - bo;
            return (a.nombre_materia || '').localeCompare(b.nombre_materia || '');
          });
        }
        this.materiasBySem = buckets;
        // Actualizar mapa de costos (filtrado por pensum actual)
        this.updateCostoMateriaMapForPensum();
      },
      error: () => {
        this.materiasBySem = { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [] };
      },
      complete: () => { this.loading = false; }
    });
  }

  onGestionChange(): void {
    // Cargar costos por materia para la gestión seleccionada
    const gestion: string = this.form.get('gestion')?.value;
    if (!gestion) { this.costoMateriaAllByGestion = []; this.costoMateriaMontoMap = {}; return; }
    this.costoMateriaService.getByGestion(gestion).subscribe({
      next: (res) => {
        this.costoMateriaAllByGestion = (res?.data || []) as any[];
        this.updateCostoMateriaMapForPensum();
      },
      error: () => { this.costoMateriaAllByGestion = []; this.costoMateriaMontoMap = {}; }
    });
  }

  private loadCreditUnit(): void {
    if (this.creditUnitLoaded) return;
    this.parametrosEconomicosService.getAll().subscribe({
      next: (res) => {
        const list = (res?.data || []) as ParametroEconomico[];
        const item = list.find(p => (p?.nombre || '').trim().toLowerCase() === 'credito');
        if (item && item.valor != null && item.valor !== '') {
          const parsed = Number(String(item.valor).replace(',', '.'));
          this.creditUnit = isNaN(parsed) ? null : parsed;
        } else {
          this.creditUnit = null;
        }
        this.creditUnitLoaded = true;
        if (this.creditUnit == null && !this.creditUnitAlertShown) {
          alert('No existe el parámetro económico "credito". No es posible calcular el monto.');
          this.creditUnitAlertShown = true;
        }
      },
      error: () => {
        this.creditUnit = null;
        this.creditUnitLoaded = true;
        if (!this.creditUnitAlertShown) {
          alert('No se pudo cargar el parámetro económico "credito".');
          this.creditUnitAlertShown = true;
        }
      }
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
