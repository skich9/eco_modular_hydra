import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { SoloNumerosDirective } from '../../../../directives/solo-numeros.directive';
import { CobrosService } from '../../../../services/cobros.service';

@Component({
  selector: 'app-regenerar-factura',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, SoloNumerosDirective],
  templateUrl: './regenerar-factura.component.html',
  styleUrls: ['./regenerar-factura.component.scss']
})
export class RegenerarFacturaComponent {
  searchForm: FormGroup;
  loading = false;
  message = '';
  messageType: 'success' | 'danger' | 'warning' | 'info' = 'info';

  rows: Array<{
    cod_ceta: number | null;
    gestion: string;
    fecha: string;
    nro_factura: number | null;
    nro_recibo: number | null;
    id_forma_cobro: string;
    concepto: string;
    observaciones: string;
    monto: number;
    metodo_pago: string;
    usuario: string;
    codigo_sucursal?: number | null;
    codigo_punto_venta?: number | string | null;
  }> = [];

  constructor(private fb: FormBuilder, private cobrosService: CobrosService) {
    this.searchForm = this.fb.group({
      cod_ceta: [''],
      nro_factura: [''],
      nro_recibo: [''],
      fecha: [''],
    });
  }

  buscar(): void {
    const codCeta = String(this.searchForm.value?.cod_ceta || '').trim();
    const nroFactura = String(this.searchForm.value?.nro_factura || '').trim();
    const nroRecibo = String(this.searchForm.value?.nro_recibo || '').trim();
    const fecha = String(this.searchForm.value?.fecha || '').trim();

    if (!codCeta && !nroFactura && !nroRecibo && !fecha) {
      this.showMessage('Ingrese al menos un criterio de búsqueda.', 'warning');
      return;
    }

    this.loading = true;
    this.rows = [];
    this.message = '';

    this.cobrosService.searchCobrosReimpresion({
      cod_ceta: codCeta || undefined,
      nro_factura: nroFactura || undefined,
      nro_recibo: nroRecibo || undefined,
      fecha: fecha || undefined,
    }).subscribe({
      next: (res: any) => {
        const data = Array.isArray(res?.data) ? res.data : [];
        this.rows = data.map((r: any) => ({
          cod_ceta: r?.cod_ceta != null ? Number(r.cod_ceta) : null,
          gestion: String(r?.gestion || ''),
          fecha: String(r?.fecha_cobro || r?.created_at || ''),
          nro_factura: r?.nro_factura != null ? Number(r.nro_factura) : null,
          nro_recibo: r?.nro_recibo != null ? Number(r.nro_recibo) : null,
          id_forma_cobro: String(r?.id_forma_cobro || '').trim().toUpperCase(),
          concepto: this.extractConcepto(r),
          observaciones: String(r?.observaciones || ''),
          monto: Number(r?.monto || 0) || 0,
          metodo_pago: this.extractMetodoPago(r),
          usuario: this.extractUsuario(r),
          codigo_sucursal: r?.factura?.codigo_sucursal ?? null,
          codigo_punto_venta: r?.factura?.codigo_punto_venta ?? null,
        }));

        if (this.rows.length === 0) {
          this.showMessage('No se encontraron registros para los filtros ingresados.', 'info');
        }
        this.loading = false;
      },
      error: (err: any) => {
        this.loading = false;
        this.showMessage(`Error en la búsqueda: ${this.getErrorMessage(err)}`, 'danger');
      }
    });
  }

  limpiar(): void {
    this.searchForm.reset();
    this.rows = [];
    this.message = '';
  }

  regenerarPdf(row: any): void {
    const nroFactura = Number(row?.nro_factura || 0);
    if (!nroFactura) {
      this.showMessage('El registro no tiene número de factura para regenerar PDF.', 'warning');
      return;
    }

    const fecha = String(row?.fecha || '');
    const anio = this.getAnioFromFecha(fecha);

    this.cobrosService.downloadFacturaPdf(anio, nroFactura, {
      codigo_sucursal: row?.codigo_sucursal ?? null,
      codigo_punto_venta: row?.codigo_punto_venta ?? null,
    }).subscribe({
      next: (blob: Blob) => {
        if (!blob || blob.size === 0) {
          this.showMessage('El servidor devolvió un PDF vacío.', 'warning');
          return;
        }

        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `Factura_${nroFactura}_${anio}.pdf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        this.showMessage(`PDF regenerado correctamente para la factura ${nroFactura}.`, 'success');
      },
      error: (err: any) => {
        this.showMessage(`No se pudo regenerar el PDF: ${this.getErrorMessage(err)}`, 'danger');
      }
    });
  }

  reimprimirRecibo(row: any): void {
    const nroRecibo = Number(row?.nro_recibo || 0);
    if (!nroRecibo) {
      this.showMessage('El registro no tiene número de recibo para reimpresión.', 'warning');
      return;
    }

    const anio = this.getAnioFromFecha(String(row?.fecha || ''));
    this.cobrosService.downloadReciboPdf(anio, nroRecibo).subscribe({
      next: (blob: Blob) => {
        this.downloadBlob(blob, `Recibo_${nroRecibo}_${anio}.pdf`);
        this.showMessage(`Recibo ${nroRecibo} reimpreso correctamente.`, 'success');
      },
      error: (err: any) => {
        this.showMessage(`No se pudo reimprimir el recibo: ${this.getErrorMessage(err)}`, 'danger');
      }
    });
  }

  reimprimirNota(row: any): void {
    const anio = this.getAnioFromFecha(String(row?.fecha || ''));
    const forma = String(row?.id_forma_cobro || '').trim().toUpperCase();
    const nroFactura = Number(row?.nro_factura || 0);
    const nroRecibo = Number(row?.nro_recibo || 0);

    if (!forma) {
      this.showMessage('No se encontró id_forma_cobro para determinar la nota a reimprimir.', 'warning');
      return;
    }

    if (this.isFormaTraspaso(forma)) {
      if (nroRecibo) {
        this.cobrosService.downloadNotaTraspasoPdfByRecibo(anio, nroRecibo).subscribe({
          next: (blob: Blob) => {
            this.downloadBlob(blob, `NotaTraspaso_Recibo_${nroRecibo}_${anio}.pdf`);
            this.showMessage(`Nota de traspaso del recibo ${nroRecibo} descargada correctamente.`, 'success');
          },
          error: (err: any) => {
            this.showMessage(`No se pudo descargar la nota de traspaso: ${this.getErrorMessage(err)}`, 'danger');
          }
        });
        return;
      }

      if (nroFactura) {
        this.cobrosService.downloadNotaTraspasoPdfByFactura(anio, nroFactura).subscribe({
          next: (blob: Blob) => {
            this.downloadBlob(blob, `NotaTraspaso_Factura_${nroFactura}_${anio}.pdf`);
            this.showMessage(`Nota de traspaso de la factura ${nroFactura} descargada correctamente.`, 'success');
          },
          error: (err: any) => {
            this.showMessage(`No se pudo descargar la nota de traspaso: ${this.getErrorMessage(err)}`, 'danger');
          }
        });
        return;
      }
    }

    if (this.isFormaBancaria(forma)) {
      if (nroRecibo) {
        this.cobrosService.downloadNotaBancariaPdfByRecibo(anio, nroRecibo).subscribe({
          next: (blob: Blob) => {
            this.downloadBlob(blob, `NotaBancaria_Recibo_${nroRecibo}_${anio}.pdf`);
            this.showMessage(`Nota bancaria del recibo ${nroRecibo} descargada correctamente.`, 'success');
          },
          error: (err: any) => {
            this.showMessage(`No se pudo descargar la nota bancaria: ${this.getErrorMessage(err)}`, 'danger');
          }
        });
        return;
      }

      if (nroFactura) {
        this.cobrosService.downloadNotaBancariaPdfByFactura(anio, nroFactura).subscribe({
          next: (blob: Blob) => {
            this.downloadBlob(blob, `NotaBancaria_Factura_${nroFactura}_${anio}.pdf`);
            this.showMessage(`Nota bancaria de la factura ${nroFactura} descargada correctamente.`, 'success');
          },
          error: (err: any) => {
            this.showMessage(`No se pudo descargar la nota bancaria: ${this.getErrorMessage(err)}`, 'danger');
          }
        });
        return;
      }
    }

    this.showMessage('La forma de cobro no tiene una nota asociada o faltan datos de recibo/factura.', 'warning');
  }

  canReimprimirNota(row: any): boolean {
    const forma = String(row?.id_forma_cobro || '').trim().toUpperCase();
    if (!forma) return false;
    return this.isFormaTraspaso(forma) || this.isFormaBancaria(forma);
  }

  private extractUsuario(r: any): string {
    if (r?.usuario && typeof r.usuario === 'object') {
      return String(r.usuario.nickname || r.usuario.nombre_completo || r.usuario.nombre || '');
    }
    if (r?.usuario) {
      return String(r.usuario);
    }
    if (r?.id_usuario != null) {
      return String(r.id_usuario);
    }
    return '';
  }

  private extractConcepto(r: any): string {
    const concepto = String(r?.concepto || '').trim();
    if (concepto) return concepto;

    const tipo = String(r?.cod_tipo_cobro || '').trim();
    if (tipo) return tipo;

    if (r?.id_item != null) return 'ITEM';
    return 'COBRO';
  }

  private extractMetodoPago(r: any): string {
    const forma = r?.forma_cobro || r?.formaCobro || null;
    if (!forma) {
      return String(r?.id_forma_cobro || '').trim();
    }

    const label = String(
      forma?.descripcion_sin ||
      forma?.nombre ||
      forma?.name ||
      forma?.descripcion ||
      forma?.id_forma_cobro ||
      ''
    ).trim();

    return label || String(r?.id_forma_cobro || '').trim();
  }

  private isFormaBancaria(forma: string): boolean {
    return ['B', 'C', 'D', 'L', 'O'].includes(forma);
  }

  private isFormaTraspaso(forma: string): boolean {
    return forma === 'T' || forma === 'TRASPASO';
  }

  private downloadBlob(blob: Blob, filename: string): void {
    if (!blob || blob.size === 0) {
      this.showMessage('El servidor devolvió un PDF vacío.', 'warning');
      return;
    }

    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }

  private getAnioFromFecha(fecha: string): number {
    const dt = new Date(fecha);
    if (!Number.isNaN(dt.getTime())) {
      return dt.getFullYear();
    }
    return new Date().getFullYear();
  }

  private getErrorMessage(err: any): string {
    return err?.error?.message || err?.message || 'Error de red o del servidor.';
  }

  private showMessage(message: string, type: 'success' | 'danger' | 'warning' | 'info'): void {
    this.message = message;
    this.messageType = type;
  }
}
