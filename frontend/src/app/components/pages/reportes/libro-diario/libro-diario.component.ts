import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { LibroDiarioService, LibroDiarioRequest, LibroDiarioItem, Usuario } from '../../../../services/reportes/libro-diario.service';
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
  datosLibroDiario: LibroDiarioItem[] = [];
  totales = { ingresos: 0, egresos: 0 };
  loading = false;
  mostrarResultados = false;
  usuarioActual: string = '';
  currentUser: any = null;
  alertMessage: string = '';
  alertType: 'success' | 'error' | 'warning' = 'success';

  constructor(
    private fb: FormBuilder,
    private libroDiarioService: LibroDiarioService,
    private authService: AuthService
  ) {
    this.filtroForm = this.fb.group({
      usuario: [{ value: '', disabled: false }, Validators.required],
      fecha: [{ value: new Date().toISOString().split('T')[0], disabled: false }, Validators.required]
    });
  }

  ngOnInit(): void {
    this.cargarUsuarios();
    this.cargarUsuarioActual();
    this.mostrarFechaActual();
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
    
    this.libroDiarioService.getUsuarios().subscribe({
      next: (response) => {
        this.usuarios = response.data || [];
        // Si no hay usuarios en la respuesta, usar el usuario actual
        if (this.usuarios.length === 0 && this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.loading = false;
        // Rehabilitar controles
        this.filtroForm.get('usuario')?.enable();
        this.filtroForm.get('fecha')?.enable();
      },
      error: (error) => {
        console.error('Error al cargar usuarios:', error);
        // Usar el usuario actual como fallback
        if (this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.loading = false;
        // Rehabilitar controles
        this.filtroForm.get('usuario')?.enable();
        this.filtroForm.get('fecha')?.enable();
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

    const request: LibroDiarioRequest = {
      usuario: this.filtroForm.value.usuario,
      fecha: this.formatearFechaSGA(this.filtroForm.value.fecha)
    };

    this.libroDiarioService.getLibroDiario(request).subscribe({
      next: (response) => {
        if (response.success) {
          this.datosLibroDiario = response.data.datos;
          this.totales = response.data.totales;
        } else {
          this.datosLibroDiario = [];
          this.totales = { ingresos: 0, egresos: 0 };
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error al obtener libro diario:', error);
        this.datosLibroDiario = [];
        this.totales = { ingresos: 0, egresos: 0 };
        this.loading = false;
      }
    });
  }

  limpiarFiltros(): void {
    this.filtroForm.reset();
    this.filtroForm.patchValue({
      usuario: '',
      fecha: new Date().toISOString().split('T')[0]
    });
    this.mostrarResultados = false;
    this.datosLibroDiario = [];
    this.totales = { ingresos: 0, egresos: 0 };
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

    const request = {
      contenido: this.generarContenidoHTML(),
      usuario: this.filtroForm.value.usuario,
      fecha: this.formatearFechaSGA(this.filtroForm.value.fecha),
      t_ingresos: this.totales.ingresos.toFixed(2),
      t_egresos: this.totales.egresos.toFixed(2),
      totales: (this.totales.ingresos - this.totales.egresos).toFixed(2)
    };

    // Mostrar confirmación (como lo hace el SGA)
    if (confirm('¿Está seguro de imprimir el Libro Diario? Se cerrará la caja y se finalizará la sesión.')) {
      this.loading = true;

      // Primero cerrar la caja
      this.libroDiarioService.cerrarCaja({
        usuario: this.filtroForm.value.usuario,
        fecha: this.formatearFechaSGA(this.filtroForm.value.fecha)
      }).subscribe({
        next: (cierreResponse) => {
          if (cierreResponse.success) {
            // Luego generar el PDF
            this.libroDiarioService.imprimirLibroDiario(request).subscribe({
              next: (pdfResponse) => {
                this.loading = false;
                if (pdfResponse.success && pdfResponse.url) {
                  // Abrir el PDF en una nueva ventana
                  window.open(pdfResponse.url, '_blank');
                  
                  // Mostrar mensaje de éxito
                  alert('Libro Diario impreso exitosamente. La caja ha sido cerrada.');
                  
                  // Opcional: cerrar sesión del usuario
                  // this.authService.logout();
                } else {
                  alert('Error al generar el PDF del libro diario');
                }
              },
              error: (error) => {
                this.loading = false;
                console.error('Error al generar PDF:', error);
                alert('Error al generar el PDF del libro diario');
              }
            });
          } else {
            this.loading = false;
            alert('Error al cerrar la caja: ' + (cierreResponse.message || 'Error desconocido'));
          }
        },
        error: (error) => {
          this.loading = false;
          console.error('Error al cerrar caja:', error);
          alert('Error al cerrar la caja');
        }
      });
    }
  }

  generarContenidoHTML(): string {
    let html = '';
    this.datosLibroDiario.forEach((item, index) => {
      html += `
        <tr class="" id="${index + 1}">
          <td class="text-center" style="border-width:1px; border-color:red; border-style:dotted;">${index + 1}</td>
          <td class="text-center" style="border-width:1px; border-color:red; border-style:dotted;">${item.recibo}</td>
          <td class="text-center" style="border-width:1px; border-color:red; border-style:dotted;">${item.factura}</td>
          <td style="border-width:1px; border-color:red; border-style:dotted;">
            ${item.concepto} / ${item.razon}
            ${item.nit && item.nit !== '0' ? ' / ' + item.nit : ''}
            ${item.cod_ceta && item.cod_ceta !== '0' ? ' / ' + item.cod_ceta : ''}
          </td>
          <td class="text-right" style="border-width:1px; border-color:red; border-style:dotted;">${item.hora}</td>
          <td class="text-right" style="border-width:1px; border-color:red; border-style:dotted;">${item.ingreso.toFixed(2)}</td>
          <td class="text-right" style="border-width:1px; border-color:red; border-style:dotted;">${item.egreso.toFixed(2)}</td>
        </tr>
      `;
    });
    return html;
  }

  /**
   * Calcula totales por método de pago
   */
  getMetodoPagoTotal(tipo: string): number {
    if (!this.datosLibroDiario || this.datosLibroDiario.length === 0) {
      return 0;
    }

    switch (tipo) {
      case 'efectivo':
        return this.datosLibroDiario
          .filter(item => item.ingreso > 0 && item.observaciones && item.observaciones.toLowerCase().includes('efectivo'))
          .reduce((sum, item) => sum + item.ingreso, 0);
      
      case 'tarjeta':
        return this.datosLibroDiario
          .filter(item => item.ingreso > 0 && item.observaciones && item.observaciones.toLowerCase().includes('tarjeta'))
          .reduce((sum, item) => sum + item.ingreso, 0);
      
      case 'efectivo_egresos':
        return this.datosLibroDiario
          .filter(item => item.egreso > 0)
          .reduce((sum, item) => sum + item.egreso, 0);
      
      default:
        return 0;
    }
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
