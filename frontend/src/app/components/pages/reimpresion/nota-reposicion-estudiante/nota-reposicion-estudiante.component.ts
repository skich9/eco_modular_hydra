import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SoloNumerosDirective } from '../../../../directives/solo-numeros.directive';
import { CobrosService } from '../../../../services/cobros.service';

@Component({
  selector: 'app-nota-reposicion-estudiante',
  standalone: true,
  imports: [CommonModule, FormsModule, SoloNumerosDirective],
  templateUrl: './nota-reposicion-estudiante.component.html',
  styleUrls: ['./nota-reposicion-estudiante.component.scss'],
})
export class NotaReposicionEstudianteComponent {
  pestanaActiva: 'numero' | 'fecha' | 'estudiante' = 'numero';

  numeroDoc = '';

  /** Valores para input type="date" (yyyy-mm-dd). */
  fechaIniIso = this.hoyIso();
  fechaFinIso = this.hoyIso();

  codCeta = '';
  apPat = '';
  apMat = '';
  nombre = '';

  lista: Array<{
    nro: number;
    documento: string;
    cont: number;
    fecha_registro: string;
    usuario: string;
    cod_ceta: number | null;
    estudiante: string;
    monto: number;
    concepto: string;
    observaciones: string;
    nro_recibo: string;
  }> = [];

  mostrarLista = false;
  cargando = false;
  mensaje = '';
  tipoMensaje: 'success' | 'danger' | 'warning' | 'info' = 'info';

  constructor(private cobrosService: CobrosService) {}

  private hoyIso(): string {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  /** Convierte yyyy-mm-dd (input nativo) a dd/mm/yyyy esperado por el API. */
  private isoToDmY(iso: string): string | null {
    const s = String(iso || '').trim();
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) {
      return null;
    }
    return `${m[3]}/${m[2]}/${m[1]}`;
  }

  elegirTab(t: 'numero' | 'fecha' | 'estudiante'): void {
    this.pestanaActiva = t;
    this.lista = [];
    this.mostrarLista = false;
    this.mensaje = '';
  }

  buscarPorDocumento(): void {
    const raw = String(this.numeroDoc || '').replace(/\s/g, '');
    const n = raw.toUpperCase();
    if (!n) {
      this.aviso('Introduzca un número para buscar.', 'warning');
      return;
    }
    if (n === '0') {
      this.aviso('Introduzca un número mayor a cero.', 'warning');
      return;
    }

    const soloDigitos = /^\d+$/.test(n);
    if (soloDigitos) {
      const len = n.length;
      if (len < 5 || len > 15) {
        this.aviso(
          'Si es código CETA, use entre 5 y 15 dígitos. Si es nota, use la clave de 8 caracteres (prefijo + año + correlativo).',
          'warning',
        );
        return;
      }
      if (len !== 8) {
        this.cargando = true;
        this.mensaje = '';
        this.buscarListaPorCodCeta(Number(n));
        return;
      }
      this.cargando = true;
      this.mensaje = '';
      this.cobrosService.notaReposicionEstudianteBuscarDoc(n).subscribe({
        next: (res) => {
          const rows = Array.isArray(res?.data) ? res.data : [];
          if (rows.length > 0) {
            this.cargando = false;
            this.lista = rows;
            this.mostrarLista = true;
            return;
          }
          this.buscarListaPorCodCeta(Number(n));
        },
        error: (err) => {
          this.cargando = false;
          this.aviso(this.msgErr(err), 'danger');
        },
      });
      return;
    }

    if (n.length !== 8) {
      this.aviso('Introduzca un número a buscar.', 'warning');
      return;
    }
    this.cargando = true;
    this.mensaje = '';
    this.cobrosService.notaReposicionEstudianteBuscarDoc(n).subscribe({
      next: (res) => {
        this.cargando = false;
        this.lista = Array.isArray(res?.data) ? res.data : [];
        this.mostrarLista = true;
        if (this.lista.length === 0) {
          this.aviso('No existen resultados para la búsqueda.', 'info');
        }
      },
      error: (err) => {
        this.cargando = false;
        this.aviso(this.msgErr(err), 'danger');
      },
    });
  }

  buscarPorFecha(): void {
    const iniIso = String(this.fechaIniIso || '').trim();
    const finIso = String(this.fechaFinIso || '').trim();
    if (!iniIso || !finIso) {
      this.aviso('Seleccione fecha inicial y fecha final.', 'warning');
      return;
    }
    const a = this.isoToDmY(iniIso);
    const b = this.isoToDmY(finIso);
    if (!a || !b) {
      this.aviso('Fechas inválidas.', 'warning');
      return;
    }
    const tIni = new Date(`${iniIso}T12:00:00`);
    const tFin = new Date(`${finIso}T12:00:00`);
    if (tIni.getTime() > tFin.getTime()) {
      this.aviso('La fecha inicial no puede ser posterior a la fecha final.', 'warning');
      return;
    }
    this.cargando = true;
    this.mensaje = '';
    this.cobrosService.notaReposicionEstudianteBuscarFecha(a, b).subscribe({
      next: (res) => {
        this.cargando = false;
        this.lista = Array.isArray(res?.data) ? res.data : [];
        this.mostrarLista = true;
        if (this.lista.length === 0) {
          this.aviso('No existen resultados para la búsqueda.', 'info');
        }
      },
      error: (err) => {
        this.cargando = false;
        this.aviso(this.msgErr(err), 'danger');
      },
    });
  }

  limpiarEstudiante(): void {
    this.lista = [];
    this.mostrarLista = false;
    this.codCeta = '';
    this.apPat = '';
    this.apMat = '';
    this.nombre = '';
    this.mensaje = '';
  }

  buscarEstudiante(): void {
    const c = String(this.codCeta || '').trim();
    if (!c || isNaN(Number(c))) {
      this.aviso('Introduzca un código CETA válido.', 'warning');
      return;
    }
    this.cargando = true;
    this.mensaje = '';
    this.cobrosService.getEstudianteBasicoPorCodCeta(c).subscribe({
      next: (res) => {
        if (!res?.success || !res?.data) {
          this.cargando = false;
          this.aviso(res?.message || 'Estudiante no encontrado.', 'warning');
          return;
        }
        const d = res.data;
        this.apPat = String(d.ap_paterno || '');
        this.apMat = String(d.ap_materno || '');
        this.nombre = String(d.nombres || '');
        this.buscarListaPorCodCeta(Number(c));
      },
      error: (err) => {
        this.cargando = false;
        this.aviso(this.msgErr(err), 'danger');
      },
    });
  }

  private buscarListaPorCodCeta(cod: number): void {
    this.cobrosService.notaReposicionEstudianteBuscarCodCeta(cod).subscribe({
      next: (res) => {
        this.cargando = false;
        this.lista = Array.isArray(res?.data) ? res.data : [];
        this.mostrarLista = true;
        if (this.lista.length === 0) {
          this.aviso('No existen resultados para la búsqueda.', 'info');
        }
      },
      error: (err) => {
        this.cargando = false;
        this.aviso(this.msgErr(err), 'danger');
      },
    });
  }

  descargar(row: { documento: string; cont: number }): void {
    const doc = String(row?.documento || '').replace(/\s/g, '').toUpperCase();
    const cont = Number(row?.cont);
    if (!doc || !Number.isFinite(cont)) {
      return;
    }
    this.cargando = true;
    this.cobrosService.notaReposicionEstudiantePdf(doc, cont).subscribe({
      next: (blob) => {
        this.cargando = false;
        if (!blob?.size) {
          this.aviso('PDF vacío o error.', 'warning');
          return;
        }
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `nota_reposicion_${doc}_${cont}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
      },
      error: (err) => {
        this.cargando = false;
        this.avisoPdfError(err);
      },
    });
  }

  private avisoPdfError(err: unknown): void {
    if (err && typeof err === 'object' && 'error' in err) {
      const e = err as { error?: Blob };
      const blob = e.error;
      if (blob && typeof blob.text === 'function') {
        blob.text().then((t) => {
          try {
            const j = JSON.parse(t);
            this.aviso(j?.message || 'Error al generar PDF.', 'danger');
          } catch {
            this.aviso('Error al generar PDF.', 'danger');
          }
        });
        return;
      }
    }
    this.aviso('Error al generar PDF.', 'danger');
  }

  private aviso(txt: string, tipo: typeof this.tipoMensaje): void {
    this.mensaje = txt;
    this.tipoMensaje = tipo;
  }

  private msgErr(err: unknown): string {
    if (err && typeof err === 'object' && 'error' in err) {
      const m = (err as { error?: { message?: string } }).error?.message;
      if (m) return m;
    }
    return 'Error de comunicación con el servidor.';
  }
}
