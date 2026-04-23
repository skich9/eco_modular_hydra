import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators, AbstractControl, ValidationErrors, ValidatorFn } from '@angular/forms';
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
  /** Texto visible (solo nickname/nombre); el id va en `usuario` del formulario. */
  usuarioLiteral = '';
  carreras: Carrera[] = [];
  datosLibroDiario: LibroDiarioItem[] = [];
  totales = { ingresos: 0, egresos: 0 };
  // Totales precomputados para evitar cálculos repetidos en el template
  resumenMetodosPago: {
    [metodo: string]: {
      factura: number;
      recibo: number;
      mora_factura: number;
      mora_recibo: number;
    };
  } = {};
  totalParcialRecibo = 0;
  totalParcialFactura = 0;
  totalParcialMoraFactura = 0;
  totalParcialMoraRecibo = 0;
  totalEfectivo = 0;
  totalGeneral = 0;
  loading = false;
  mostrarResultados = false;
  usuarioActual: string = '';
  currentUser: any = null;
  usuarioInfo: { hora_apertura?: string; hora_cierre?: string; nombre?: string } = {};
  alertMessage: string = '';
  alertType: 'success' | 'error' | 'warning' = 'success';

  /**
   * Filas de datos por página en el PDF (sin contar la fila "Subtotal página").
   * Backend acepta 5–80; valores fuera de rango se recortan.
   */
  readonly filasLibroDiarioPdfPorPagina = 50;

  constructor(
    private fb: FormBuilder,
    private libroDiarioService: LibroDiarioService,
    private authService: AuthService
  ) {
    this.filtroForm = this.fb.group({
      usuario: [
        { value: '', disabled: false },
        [Validators.required, this.usuarioDeListaValidator()]
      ],
      fecha: [{ value: new Date().toISOString().split('T')[0], disabled: false }, Validators.required],
      carrera: [{ value: '', disabled: false }, Validators.required]
    });
  }

  /** Valida que el valor sea un id_usuario presente en la lista cargada (combinado con input+datalist). */
  private usuarioDeListaValidator(): ValidatorFn {
    return (control: AbstractControl): ValidationErrors | null => {
      const v = String(control.value ?? '').trim();
      if (!v) {
        return null;
      }
      if (!this.usuarios.length) {
        return null;
      }
      const ok = this.usuarios.some((u) => String(u.id_usuario) === v);
      return ok ? null : { usuarioNoEnLista: true };
    };
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
        this.carreras = (response.data || []).sort((a, b) =>
          (a.nombre || '').localeCompare((b.nombre || ''), 'es', { sensitivity: 'base' })
        );
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
        // El select usa id_usuario como value, no nombre.
        this.usuarioActual = String(user.id_usuario ?? '');
        if (this.usuarioActual) {
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
          this.syncUsuarioLiteralDesdeId(this.usuarioActual);
        }
        this.aplicarRestriccionUsuario();
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
        this.usuarios = this.ordenarUsuariosPorNombre(response.data || []);
        // Si no hay usuarios en la respuesta, usar el usuario actual
        if (this.usuarios.length === 0 && this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.filtroForm.get('usuario')?.updateValueAndValidity({ emitEvent: false });
        this.loading = false;
        this.filtroForm.get('fecha')?.enable();
        this.filtroForm.get('carrera')?.enable();
        this.aplicarRestriccionUsuario();
        this.syncUsuarioLiteralDesdeId(String(this.filtroForm.getRawValue().usuario ?? ''));
      },
      error: (error) => {
        console.error('Error al cargar usuarios:', error);
        if (this.usuarioActual) {
          this.usuarios = [{ id_usuario: this.usuarioActual, nombre: this.usuarioActual }];
          this.filtroForm.patchValue({ usuario: this.usuarioActual });
        }
        this.loading = false;
        this.filtroForm.get('fecha')?.enable();
        this.filtroForm.get('carrera')?.enable();
        this.filtroForm.get('usuario')?.updateValueAndValidity({ emitEvent: false });
        this.aplicarRestriccionUsuario();
        this.syncUsuarioLiteralDesdeId(String(this.filtroForm.getRawValue().usuario ?? ''));
      }
    });
  }

  /** Nickname o nombre para mostrar (sin prefijo numérico). */
  textoLiteralUsuario(u: Usuario): string {
    const n = String(u.nickname || u.nombre || '').trim();
    return n || String(u.id_usuario);
  }

  /** Sincroniza el texto visible a partir del id almacenado en el formulario. */
  syncUsuarioLiteralDesdeId(id: string): void {
    if (!this.puedeElegirUsuarioLibroDiario()) {
      return;
    }
    if (!id) {
      this.usuarioLiteral = '';
      return;
    }
    const u = this.usuarios.find((x) => String(x.id_usuario) === String(id));
    this.usuarioLiteral = u ? this.textoLiteralUsuario(u) : String(id);
  }

  /**
   * Resuelve id_usuario desde el texto visible y actualiza el control oculto.
   * (Selección datalist, blur o antes de buscar.)
   */
  resolverUsuarioLiteral(): void {
    if (!this.puedeElegirUsuarioLibroDiario()) {
      return;
    }
    const ctrl = this.filtroForm.get('usuario');
    if (!ctrl) {
      return;
    }
    const raw = this.usuarioLiteral.trim();
    if (!raw) {
      ctrl.patchValue('', { emitEvent: false });
      ctrl.updateValueAndValidity({ emitEvent: false });
      return;
    }
    if (!this.usuarios.length) {
      return;
    }
    if (this.usuarios.some((u) => String(u.id_usuario) === raw)) {
      const u = this.usuarios.find((x) => String(x.id_usuario) === raw)!;
      this.usuarioLiteral = this.textoLiteralUsuario(u);
      ctrl.patchValue(String(u.id_usuario), { emitEvent: false });
      ctrl.updateValueAndValidity({ emitEvent: false });
      return;
    }
    const porLiteralExacto = this.usuarios.find(
      (u) => this.textoLiteralUsuario(u).toLowerCase() === raw.toLowerCase()
    );
    if (porLiteralExacto) {
      this.usuarioLiteral = this.textoLiteralUsuario(porLiteralExacto);
      ctrl.patchValue(String(porLiteralExacto.id_usuario), { emitEvent: false });
      ctrl.updateValueAndValidity({ emitEvent: false });
      return;
    }
    const coincidencias = this.usuarios.filter((u) => {
      const lit = this.textoLiteralUsuario(u).toLowerCase();
      const conId = `${u.id_usuario} - ${u.nickname || u.nombre || u.id_usuario}`.toLowerCase();
      return lit.includes(raw.toLowerCase()) || conId.includes(raw.toLowerCase());
    });
    if (coincidencias.length === 1) {
      const u = coincidencias[0];
      this.usuarioLiteral = this.textoLiteralUsuario(u);
      ctrl.patchValue(String(u.id_usuario), { emitEvent: false });
      ctrl.updateValueAndValidity({ emitEvent: false });
    }
  }

  onUsuarioInputBlur(): void {
    this.resolverUsuarioLiteral();
  }

  /**
   * Al elegir una opción del datalist, el navegador pone el `value` (id numérico) en el input.
   * Suele dispararse `input` con inputType `insertReplacementText`; sustituimos por el texto literal
   * tras un tick para que `ngModel` ya tenga el id.
   */
  onUsuarioInputEvent(ev: Event): void {
    if (!this.puedeElegirUsuarioLibroDiario()) {
      return;
    }
    const ie = ev as InputEvent;
    if (ie.inputType === 'insertReplacementText') {
      setTimeout(() => this.resolverUsuarioLiteral());
    }
  }

  onUsuarioDatalistChange(): void {
    setTimeout(() => this.resolverUsuarioLiteral());
  }

  buscarLibroDiario(): void {
    if (this.puedeElegirUsuarioLibroDiario()) {
      this.resolverUsuarioLiteral();
    }
    if (this.filtroForm.invalid) {
      this.filtroForm.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.mostrarResultados = true;

    const { usuario, fecha, carrera } = this.filtroForm.getRawValue();
    const usuarioFiltro = this.puedeElegirUsuarioLibroDiario()
      ? usuario
      : (this.usuarioActual || usuario);
    if (!this.usuarioPermitidoParaLibroDiario(String(usuarioFiltro))) {
      this.loading = false;
      this.mostrarResultados = false;
      this.mostrarAlerta(
        'No puede consultar el Libro Diario de ese usuario. Su rol solo permite el propio libro, salvo rector, tesorería, contabilidad o sistemas.',
        'warning'
      );
      return;
    }
    const fechaSGA = this.formatearFechaSGA(fecha);

    const request: LibroDiarioRequest = {
      usuario: usuarioFiltro,
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
          if (response.message) {
            this.mostrarAlerta(response.message, 'warning');
          }
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
    this.usuarioLiteral = '';
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

    const { usuario, fecha: fechaValue, carrera: codigoCarrera } = this.filtroForm.getRawValue();
    const usuarioFiltro = this.puedeElegirUsuarioLibroDiario()
      ? usuario
      : (this.usuarioActual || usuario);
    if (!this.usuarioPermitidoParaLibroDiario(String(usuarioFiltro))) {
      this.mostrarAlerta(
        'No puede imprimir el Libro Diario de ese usuario. Su rol solo permite el propio libro, salvo rector, tesorería, contabilidad o sistemas.',
        'warning'
      );
      return;
    }

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

    const selectedUser = this.usuarios.find(u => String(u.id_usuario) === String(usuarioFiltro));
    const usuarioDisplay = selectedUser
      ? String(selectedUser.nickname || selectedUser.nombre || selectedUser.id_usuario)
      : String(usuarioFiltro);

    // Mostrar confirmación (como lo hace el SGA)
    if (confirm('¿Está seguro de imprimir el Libro Diario? Se cerrará la caja y se finalizará la sesión.')) {
      // Abrir nueva pestaña inmediatamente (sincronizado con el click) para evitar bloqueo de popups
      const win = window.open('', '_blank');

      this.loading = true;

      // Primero cerrar la caja (obtiene id_libro_diario_cierre / orden_cierre para RD-{carrera}-{mes}-{correlativo})
      this.libroDiarioService.cerrarCaja({ usuario: usuarioFiltro, fecha, codigo_carrera: codigoCarrera })
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
            const resumenMetodo = (m: string) => ({
              factura: this.resumenMetodosPago?.[m]?.factura ?? 0,
              recibo: this.resumenMetodosPago?.[m]?.recibo ?? 0,
              mora_factura: this.resumenMetodosPago?.[m]?.mora_factura ?? 0,
              mora_recibo: this.resumenMetodosPago?.[m]?.mora_recibo ?? 0,
            });
            const resumen = {
              traspaso: resumenMetodo('traspaso'),
              deposito: resumenMetodo('deposito'),
              efectivo: resumenMetodo('efectivo'),
              cheque: resumenMetodo('cheque'),
              tarjeta: resumenMetodo('tarjeta'),
              transferencia: resumenMetodo('transferencia'),
              otro: resumenMetodo('otro'),
              total_factura: this.totalParcialFactura,
              total_recibo: this.totalParcialRecibo,
              total_mora_factura: this.totalParcialMoraFactura,
              total_mora_recibo: this.totalParcialMoraRecibo,
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
              usuario: usuarioFiltro,
              usuario_display: usuarioDisplay,
              fecha,
              t_ingresos: this.totales.ingresos.toFixed(2),
              t_egresos: '0.00',
              totales: this.totalGeneral.toFixed(2),
              resumen,
              filas_por_pagina: this.filasLibroDiarioPdfPorPagina
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

  private ordenarUsuariosPorNombre(usuarios: Usuario[]): Usuario[] {
    return [...usuarios].sort((a, b) => {
      const nombreA = String(a.nickname || a.nombre || a.id_usuario || '');
      const nombreB = String(b.nickname || b.nombre || b.id_usuario || '');
      return nombreA.localeCompare(nombreB, 'es', { sensitivity: 'base' });
    });
  }

  /**
   * La API devolvió más de un usuario (p. ej. rol con visión global: rector, tesorería, contabilidad, sistemas).
   */
  puedeElegirUsuarioLibroDiario(): boolean {
    return this.usuarios.length > 1;
  }

  private usuarioPermitidoParaLibroDiario(idUsuario: string): boolean {
    if (!idUsuario) {
      return false;
    }
    return this.usuarios.some((u) => String(u.id_usuario) === String(idUsuario));
  }

  /** Etiqueta legible para usuario no admin (solo lectura). */
  get etiquetaUsuarioSesion(): string {
    const id = this.usuarioActual;
    if (!id) {
      return '';
    }
    const u = this.usuarios.find((x) => String(x.id_usuario) === String(id));
    if (u) {
      return this.textoLiteralUsuario(u);
    }
    return id;
  }

  private aplicarRestriccionUsuario(): void {
    const usuarioCtrl = this.filtroForm.get('usuario');
    if (!usuarioCtrl) {
      return;
    }

    if (this.puedeElegirUsuarioLibroDiario()) {
      usuarioCtrl.enable({ emitEvent: false });
      this.syncUsuarioLiteralDesdeId(String(this.filtroForm.get('usuario')?.value ?? ''));
      return;
    }

    this.usuarioLiteral = '';

    if (this.usuarioActual) {
      const idActual = String(this.usuarioActual);
      this.usuarios = this.usuarios.filter(u => String(u.id_usuario) === idActual);
      if (this.usuarios.length === 0) {
        this.usuarios = [{ id_usuario: idActual, nombre: this.currentUser?.nombre || idActual }];
      }
      this.filtroForm.patchValue({ usuario: idActual }, { emitEvent: false });
    }

    usuarioCtrl.disable({ emitEvent: false });
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

      // Si es el método de pago correcto, filtrar por tipo de comprobante y por mora
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
          if (tieneFacturaValida) {
            esFacturaDoc = true;
          } else {
            esReciboDoc = true;
          }
        }

        const esMora = !!item.es_mora;
        const ing = Number(item.ingreso) || 0;
        const mInf = Number(item.monto_mora) || 0;
        const mMora = esMora ? ing : Math.min(Math.max(0, mInf), ing);
        const mCapital = esMora ? 0 : Math.max(0, ing - mMora);

        if (tipoComprobante === 'factura' && esFacturaDoc && !esMora) {
          total += mCapital;
        } else if (tipoComprobante === 'recibo' && esReciboDoc && !esMora) {
          total += mCapital;
        } else if (tipoComprobante === 'mora_factura' && esFacturaDoc && (esMora || mMora > 0.00001)) {
          total += esMora ? ing : mMora;
        } else if (tipoComprobante === 'mora_recibo' && esReciboDoc && (esMora || mMora > 0.00001)) {
          total += esMora ? ing : mMora;
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
    const resumen: { [m: string]: { factura: number; recibo: number; mora_factura: number; mora_recibo: number } } = {} as any;

    metodos.forEach(m => {
      resumen[m] = {
        recibo: this.getMetodoPagoTotalByType(m, 'recibo'),
        factura: this.getMetodoPagoTotalByType(m, 'factura'),
        mora_factura: this.getMetodoPagoTotalByType(m, 'mora_factura'),
        mora_recibo: this.getMetodoPagoTotalByType(m, 'mora_recibo')
      };
    });

    this.resumenMetodosPago = resumen;

    const sumar = (campo: 'factura' | 'recibo' | 'mora_factura' | 'mora_recibo'): number =>
      metodos.reduce((acc, m) => acc + (resumen[m]?.[campo] || 0), 0);

    this.totalParcialRecibo = sumar('recibo');
    this.totalParcialFactura = sumar('factura');
    this.totalParcialMoraFactura = sumar('mora_factura');
    this.totalParcialMoraRecibo = sumar('mora_recibo');

    // Total Efectivo: solo factura/recibo en efectivo, sin mora.
    this.totalEfectivo =
      (resumen['efectivo']?.factura || 0) +
      (resumen['efectivo']?.recibo || 0);

    this.totalGeneral =
      this.totalParcialFactura +
      this.totalParcialRecibo +
      this.totalParcialMoraFactura +
      this.totalParcialMoraRecibo;
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
