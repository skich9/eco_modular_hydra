import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { LibroDiarioService, LibroDiarioRequest, LibroDiarioItem, Usuario, Carrera } from '../../../../services/reportes/libro-diario.service';
import { AuthService } from '../../../../services/auth.service';

@Component({
  selector: 'app-libro-diario',
  standalone: true,
  imports: [CommonModule, FormsModule, ReactiveFormsModule],
  templateUrl: './libro-diario.component.html',
  styleUrls: ['./libro-diario.component.scss']
})
export class LibroDiarioComponent implements OnInit {
  filtroForm: FormGroup;
  usuarios: Usuario[] = [];
  carreras: Carrera[] = [];
  datosLibroDiario: LibroDiarioItem[] = [];
  totales = { ingresos: 0, egresos: 0 };
  // Totales precomputados para evitar cálculos repetidos en el template
  resumenMetodosPago: {
    [metodo: string]: {
      factura: number;
      recibo: number;
    };
  } = {};
  totalParcialRecibo = 0;
  totalParcialFactura = 0;
  totalEfectivo = 0;
  totalGeneral = 0;
  loading = false;
  mostrarResultados = false;
  usuarioActual: string = '';
  currentUser: any = null;
  usuarioInfo: { hora_apertura?: string; hora_cierre?: string; nombre?: string } = {};
  alertMessage: string = '';
  alertType: 'success' | 'error' | 'warning' = 'success';

  constructor(
    private fb: FormBuilder,
    private libroDiarioService: LibroDiarioService,
    private authService: AuthService
  ) {
    this.filtroForm = this.fb.group({
      usuario: [{ value: '', disabled: false }, Validators.required],
      fecha: [{ value: new Date().toISOString().split('T')[0], disabled: false }, Validators.required],
      carrera: [{ value: '', disabled: false }, Validators.required]
    });
  }

  ngOnInit(): void {
    this.cargarUsuarios();
    this.cargarCarreras();
    this.cargarUsuarioActual();
    this.mostrarFechaActual();
  }

  cargarCarreras(): void {
    this.libroDiarioService.getCarreras().subscribe({
      next: (response) => {
        this.carreras = response.data || [];
      },
      error: () => {
        this.carreras = [];
      }
    });
  }

  getCarreraNombre(codigoCarrera: string): string {
    const c = this.carreras.find(x => x.codigo_carrera === codigoCarrera);
    return c?.nombre || codigoCarrera || '';
  }

  /**
   * Muestra la fecha actual en el header
   */
  mostrarFechaActual(): void {
    const fechaActual = new Date();
    const opciones: Intl.DateTimeFormatOptions = { 
      weekday: 'long', 
      year: 'numeric', 
      month: 'long', 
      day: 'numeric' 
    };
    const fechaFormateada = fechaActual.toLocaleDateString('es-ES', opciones);
    
    // Actualizar el span con id fechaActual
    setTimeout(() => {
      const elementoFecha = document.getElementById('fechaActual');
      if (elementoFecha) {
        elementoFecha.textContent = fechaFormateada.charAt(0).toUpperCase() + fechaFormateada.slice(1);
      }
    }, 100);
  }

  cargarUsuarioActual(): void {
    // Obtener el usuario actual del servicio de autenticación
    this.authService.currentUser$.subscribe(user => {
      if (user) {
        this.currentUser = user;
        this.usuarioActual = String(user.nombre || user.id_usuario || '');
        // Pre-seleccionar el usuario actual si está en la lista
        if (this.usuarioActual) {
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
      }
    });
  }

  cargarUsuarios(): void {
    this.loading = true;
    // Deshabilitar controles mientras carga
    this.filtroForm.get('usuario')?.disable();
    this.filtroForm.get('fecha')?.disable();
    this.filtroForm.get('carrera')?.disable();

    this.libroDiarioService.getUsuarios().subscribe({
      next: (response) => {
        this.usuarios = response.data || [];
        // Si no hay usuarios en la respuesta, usar el usuario actual
        if (this.usuarios.length === 0 && this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.loading = false;
        this.filtroForm.get('usuario')?.enable();
        this.filtroForm.get('fecha')?.enable();
        this.filtroForm.get('carrera')?.enable();
      },
      error: (error) => {
        console.error('Error al cargar usuarios:', error);
        if (this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.loading = false;
        this.filtroForm.get('usuario')?.enable();
        this.filtroForm.get('fecha')?.enable();
        this.filtroForm.get('carrera')?.enable();
      }
    });
  }

  buscarLibroDiario(): void {
    if (this.filtroForm.invalid) {
      this.filtroForm.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.mostrarResultados = true;

    const { usuario, fecha, carrera } = this.filtroForm.value;
    const fechaSGA = this.formatearFechaSGA(fecha);

    const request: LibroDiarioRequest = {
      usuario,
      codigo_carrera: carrera || undefined,
      fecha: fechaSGA,
      fecha_inicio: fechaSGA,
      fecha_fin: fechaSGA
    };

    this.libroDiarioService.getLibroDiario(request).subscribe({
      next: (response) => {
        if (response.success) {
          this.datosLibroDiario = response.data.datos || [];
          this.usuarioInfo = response.data.usuario_info || {};
          this.datosLibroDiario.sort((a: any, b: any) => {
            const ha = (a?.hora || '').toString();
            const hb = (b?.hora || '').toString();
            if (ha && hb) {
              return ha.localeCompare(hb);
            }
            if (!ha && hb) {
              return 1;
            }
            if (ha && !hb) {
              return -1;
            }
            return 0;
          });
          this.totales = response.data.totales;
          this.recalcularResumenMetodosPago();
        } else {
          this.datosLibroDiario = [];
          this.totales = { ingresos: 0, egresos: 0 };
          this.recalcularResumenMetodosPago();
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error al obtener libro diario:', error);
        this.datosLibroDiario = [];
        this.totales = { ingresos: 0, egresos: 0 };
        this.recalcularResumenMetodosPago();
        this.loading = false;
      }
    });
  }

  limpiarFiltros(): void {
    this.filtroForm.reset();
    this.filtroForm.patchValue({
      usuario: '',
      fecha: new Date().toISOString().split('T')[0],
      carrera: ''
    });
    this.mostrarResultados = false;
    this.datosLibroDiario = [];
    this.usuarioInfo = {};
    this.totales = { ingresos: 0, egresos: 0 };
    this.recalcularResumenMetodosPago();
    this.limpiarAlerta();
  }

  /**
   * Muestra una alerta
   */
  mostrarAlerta(mensaje: string, tipo: 'success' | 'error' | 'warning' = 'success'): void {
    this.alertMessage = mensaje;
    this.alertType = tipo;
    
    // Auto limpiar después de 5 segundos
    setTimeout(() => {
      this.limpiarAlerta();
    }, 5000);
  }

  /**
   * Limpia la alerta actual
   */
  limpiarAlerta(): void {
    this.alertMessage = '';
    this.alertType = 'success';
  }

  imprimirLibroDiario(): void {
    if (!this.datosLibroDiario || this.datosLibroDiario.length === 0) {
      return;
    }

    const { usuario, fecha: fechaValue, carrera: codigoCarrera } = this.filtroForm.value;

    if (!fechaValue) {
      this.mostrarAlerta('Debe seleccionar una fecha para imprimir el Libro Diario.', 'warning');
      return;
    }
    if (!codigoCarrera) {
      this.mostrarAlerta('Debe seleccionar una carrera para imprimir el Libro Diario.', 'warning');
      return;
    }

    const fecha = this.formatearFechaSGA(fechaValue);
    const carreraSel = this.carreras.find(c => c.codigo_carrera === codigoCarrera);
    const carreraNombre = carreraSel?.nombre || codigoCarrera;

    const selectedUser = this.usuarios.find(u => String(u.id_usuario) === String(usuario));
    const usuarioDisplay = selectedUser
      ? `${selectedUser.id_usuario} - ${selectedUser.nickname || selectedUser.nombre || selectedUser.id_usuario}`
      : usuario;

    // Mostrar confirmación (como lo hace el SGA)
    if (confirm('¿Está seguro de imprimir el Libro Diario? Se cerrará la caja y se finalizará la sesión.')) {
      // Abrir nueva pestaña inmediatamente (sincronizado con el click) para evitar bloqueo de popups
      const win = window.open('', '_blank');

      this.loading = true;

      // Primero cerrar la caja (obtiene id_libro_diario_cierre / orden_cierre para RD-{carrera}-{mes}-{correlativo})
      this.libroDiarioService.cerrarCaja({ usuario, fecha, codigo_carrera: codigoCarrera })
      .subscribe({
        next: (cierreResponse) => {
          if (cierreResponse.success) {
            const ahora = new Date();
            const horaActualStr = `${String(ahora.getHours()).padStart(2, '0')}:${String(ahora.getMinutes()).padStart(2, '0')}:${String(ahora.getSeconds()).padStart(2, '0')}`;
            const horaCierre =
              (cierreResponse.hora_cierre && cierreResponse.hora_cierre.trim() !== '')
                ? cierreResponse.hora_cierre.trim()
                : (this.usuarioInfo?.hora_cierre?.trim() || horaActualStr);
            this.usuarioInfo = { ...this.usuarioInfo, hora_cierre: horaCierre };

            // Resumen: correlativo = id de libro_diario_cierre (único); orden_cierre ayuda a localizar la fila
            const resumen = {
              traspaso: { factura: this.resumenMetodosPago?.['traspaso']?.factura ?? 0, recibo: this.resumenMetodosPago?.['traspaso']?.recibo ?? 0 },
              deposito: { factura: this.resumenMetodosPago?.['deposito']?.factura ?? 0, recibo: this.resumenMetodosPago?.['deposito']?.recibo ?? 0 },
              efectivo: { factura: this.resumenMetodosPago?.['efectivo']?.factura ?? 0, recibo: this.resumenMetodosPago?.['efectivo']?.recibo ?? 0 },
              cheque: { factura: this.resumenMetodosPago?.['cheque']?.factura ?? 0, recibo: this.resumenMetodosPago?.['cheque']?.recibo ?? 0 },
              tarjeta: { factura: this.resumenMetodosPago?.['tarjeta']?.factura ?? 0, recibo: this.resumenMetodosPago?.['tarjeta']?.recibo ?? 0 },
              transferencia: { factura: this.resumenMetodosPago?.['transferencia']?.factura ?? 0, recibo: this.resumenMetodosPago?.['transferencia']?.recibo ?? 0 },
              otro: { factura: this.resumenMetodosPago?.['otro']?.factura ?? 0, recibo: this.resumenMetodosPago?.['otro']?.recibo ?? 0 },
              total_factura: this.totalParcialFactura,
              total_recibo: this.totalParcialRecibo,
              total_efectivo: this.totalEfectivo,
              total_general: this.totalGeneral,
              hora_apertura: this.usuarioInfo?.hora_apertura || '',
              hora_cierre: horaCierre,
              carrera: carreraNombre,
              codigo_carrera: codigoCarrera,
              orden_cierre: cierreResponse.orden_cierre ?? 1,
              ...(typeof cierreResponse.id_libro_diario_cierre === 'number'
                ? { id_libro_diario_cierre: cierreResponse.id_libro_diario_cierre }
                : {}),
              ...(typeof cierreResponse.correlativo === 'number'
                ? { correlativo: cierreResponse.correlativo }
                : {}),
              ...(cierreResponse.codigo_rd ? { codigo_rd: cierreResponse.codigo_rd } : {})
            };

            const request = {
              contenido: this.generarContenidoHTML(),
              datos: this.datosLibroDiario,
              usuario,
              usuario_display: usuarioDisplay,
              fecha,
              t_ingresos: this.totales.ingresos.toFixed(2),
              t_egresos: '0.00',
              totales: this.totalGeneral.toFixed(2),
              resumen
            };

            // Luego generar el PDF
            this.libroDiarioService.imprimirLibroDiario(request).subscribe({
              next: (pdfResponse) => {
                this.loading = false;
                if (pdfResponse.success && pdfResponse.url) {
                  // Añadir timestamp para evitar caché del navegador
                  const urlPdf = pdfResponse.url + (pdfResponse.url.includes('?') ? '&' : '?') + 't=' + Date.now();
                  if (win && !win.closed) {
                    win.location.href = urlPdf;
                  } else {
                    window.location.href = urlPdf;
                  }
                  
                  // Mostrar mensaje de éxito
                  this.mostrarAlerta('Libro Diario impreso exitosamente. La caja ha sido cerrada.', 'success');
                  
                  // Opcional: cerrar sesión del usuario
                  // this.authService.logout();
                } else {
                  this.mostrarAlerta(pdfResponse.message || 'Error al generar el PDF del libro diario', 'error');
                }
              },
              error: (error) => {
                this.loading = false;
                console.error('Error al generar PDF:', error);
                this.mostrarAlerta('Error al generar el PDF del libro diario', 'error');
              }
            });
          } else {
            this.loading = false;
            this.mostrarAlerta('Error al cerrar la caja: ' + (cierreResponse.message || 'Error desconocido'), 'error');
          }
        },
        error: (error) => {
          this.loading = false;
          console.error('Error al cerrar caja:', error);
          this.mostrarAlerta('Error al cerrar la caja', 'error');
        }
      });
    }
  }

  /**
   * Genera filas HTML con 11 columnas según plantilla SGA:
   * P | Nº | Recibo | Factura | Razón Social | NIT - C.I. | Concepto | Observación | Código CETA | Hora | Ingresos
   */
  generarContenidoHTML(): string {
    const estilo = 'border: 1px solid #000; padding: 2px 3px;';
    let html = '';
    this.datosLibroDiario.forEach((item, index) => {
      const p = (item.tipo_pago || 'E').toString();
      const razon = (item.razon || '').toString();
      const nit = (item.nit && item.nit !== '0' ? item.nit : '');
      const concepto = (item.concepto || '').toString();
      const obs = (item.observaciones || '').toString();
      const codCeta = (item.cod_ceta && item.cod_ceta !== '0' ? item.cod_ceta : '');
      const hora = (item.hora || '').toString();
      const ingreso = item.ingreso > 0 ? item.ingreso.toFixed(2) : '';

      html += `<tr id="${index + 1}">
        <td class="text-center" style="${estilo}">${p}</td>
        <td class="text-center" style="${estilo}">${index + 1}</td>
        <td class="text-center" style="${estilo}">${item.recibo || '0'}</td>
        <td class="text-center" style="${estilo}">${item.factura || '0'}</td>
        <td style="${estilo}">${razon}</td>
        <td class="text-center" style="${estilo}">${nit}</td>
        <td style="${estilo}">${concepto}</td>
        <td style="${estilo}">${obs}</td>
        <td class="text-center" style="${estilo}">${codCeta}</td>
        <td class="text-center" style="${estilo}">${hora}</td>
        <td class="text-right" style="${estilo}">${ingreso}</td>
      </tr>`;
    });
    return html;
  }

  getMetodoPagoTotal(tipo: string): number {
    if (!this.datosLibroDiario || this.datosLibroDiario.length === 0) {
      return 0;
    }

    let total = 0;
    this.datosLibroDiario.forEach(item => {
      if (tipo === 'efectivo' && item.tipo_pago === 'E') {
        total += item.ingreso;
      } else if (tipo === 'tarjeta' && (item.tipo_pago === 'L' || item.tipo_pago === 'B')) {
        // Tarjeta incluye también Transferencia (B) - se consolidan en la misma fila
        total += item.ingreso;
      } else if (tipo === 'deposito' && item.tipo_pago === 'D') {
        total += item.ingreso;
      } else if (tipo === 'cheque' && item.tipo_pago === 'C') {
        total += item.ingreso;
      } else if (tipo === 'transferencia' && item.tipo_pago === 'B') {
        // Transferencia (B) se consolida en fila Tarjeta; no sumar aquí para evitar duplicados
      } else if (tipo === 'otro' && item.tipo_pago === 'O') {
        total += item.ingreso;
      }
    });

    return total;
  }

  getMetodoPagoTotalByType(metodoPago: string, tipoComprobante: string): number {
    if (!this.datosLibroDiario || this.datosLibroDiario.length === 0) {
      return 0;
    }

    let total = 0;
    this.datosLibroDiario.forEach(item => {
      let esMetodoPagoCorrecto = false;
      
      // Determinar si el item corresponde al método de pago
      // Nota: Transferencia (B) se suma en la fila Tarjeta según requerimiento del reporte
      if (metodoPago === 'traspaso' && item.tipo_pago === 'T') {
        esMetodoPagoCorrecto = true;
      } else if (metodoPago === 'efectivo' && item.tipo_pago === 'E') {
        esMetodoPagoCorrecto = true;
      } else if (metodoPago === 'tarjeta' && (item.tipo_pago === 'L' || item.tipo_pago === 'B')) {
        esMetodoPagoCorrecto = true; // Tarjeta + Transferencia consolidados
      } else if (metodoPago === 'deposito' && item.tipo_pago === 'D') {
        esMetodoPagoCorrecto = true;
      } else if (metodoPago === 'cheque' && item.tipo_pago === 'C') {
        esMetodoPagoCorrecto = true;
      } else if (metodoPago === 'transferencia' && item.tipo_pago === 'B') {
        // B ya se cuenta en Tarjeta; aquí 0 para evitar duplicar (fila Transferencia queda vacía)
        esMetodoPagoCorrecto = false;
      } else if (metodoPago === 'otro' && item.tipo_pago === 'O') {
        esMetodoPagoCorrecto = true;
      }

      // Si es el método de pago correcto, filtrar por tipo de comprobante
      if (esMetodoPagoCorrecto) {
        const tipoDocRaw = (item as any).tipo_doc ? String((item as any).tipo_doc).toUpperCase() : '';
        const tieneFacturaValida = !!item.factura && item.factura !== '0' && item.factura !== '';

        let esFacturaDoc = false;
        let esReciboDoc = false;

        if (tipoDocRaw === 'F') {
          esFacturaDoc = true;
        } else if (tipoDocRaw === 'R') {
          esReciboDoc = true;
        } else {
          // Fallback: si no tenemos tipo_doc, usar la presencia de factura válida
          if (tieneFacturaValida) {
            esFacturaDoc = true;
          } else {
            esReciboDoc = true;
          }
        }

        if (tipoComprobante === 'factura' && esFacturaDoc) {
          total += item.ingreso;
        } else if (tipoComprobante === 'recibo' && esReciboDoc) {
          total += item.ingreso;
        }
      }
    });

    return total;
  }

  /**
   * Recalcula todos los totales de métodos de pago y totales generales
   * para que el template sólo lea propiedades ya calculadas.
   */
  private recalcularResumenMetodosPago(): void {
    const metodos = ['traspaso', 'deposito', 'efectivo', 'cheque', 'tarjeta', 'transferencia', 'otro'];
    const resumen: any = {};

    metodos.forEach(m => {
      resumen[m] = {
        recibo: this.getMetodoPagoTotalByType(m, 'recibo'),
        factura: this.getMetodoPagoTotalByType(m, 'factura')
      };
    });

    this.resumenMetodosPago = resumen;

    this.totalParcialRecibo =
      (resumen['traspaso']?.recibo || 0) +
      (resumen['deposito']?.recibo || 0) +
      (resumen['efectivo']?.recibo || 0) +
      (resumen['cheque']?.recibo || 0) +
      (resumen['tarjeta']?.recibo || 0) +
      (resumen['transferencia']?.recibo || 0) +
      (resumen['otro']?.recibo || 0);

    this.totalParcialFactura =
      (resumen['traspaso']?.factura || 0) +
      (resumen['deposito']?.factura || 0) +
      (resumen['efectivo']?.factura || 0) +
      (resumen['cheque']?.factura || 0) +
      (resumen['tarjeta']?.factura || 0) +
      (resumen['transferencia']?.factura || 0) +
      (resumen['otro']?.factura || 0);

    this.totalEfectivo = resumen['efectivo'].recibo + resumen['efectivo'].factura;

    this.totalGeneral =
      this.totalParcialRecibo +
      this.totalParcialFactura;
  }

  /**
   * Formatea la fecha al formato que espera el SGA (d/m/Y)
   */
  private formatearFechaSGA(fecha: string): string {
    let fechaFormateada = '';
    
    if (typeof fecha === 'string') {
      if (fecha.includes('-')) {
        const partes = fecha.split('-');
        if (partes.length === 3) {
          fechaFormateada = `${partes[2]}/${partes[1]}/${partes[0]}`;
        }
      } else if (fecha.includes('/')) {
        fechaFormateada = fecha;
      } else {
        const date = new Date(fecha);
        if (!isNaN(date.getTime())) {
          const day = date.getDate().toString().padStart(2, '0');
          const month = (date.getMonth() + 1).toString().padStart(2, '0');
          const year = date.getFullYear();
          fechaFormateada = `${day}/${month}/${year}`;
        }
      }
    } else {
      const date = new Date(fecha);
      if (!isNaN(date.getTime())) {
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear();
        fechaFormateada = `${day}/${month}/${year}`;
      }
    }
    
    return fechaFormateada;
  }
}
