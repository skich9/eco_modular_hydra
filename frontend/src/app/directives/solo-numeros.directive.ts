import { Directive, HostListener } from '@angular/core';

@Directive({
  selector: '[appSoloNumeros]',
  standalone: true
})
export class SoloNumerosDirective {

  @HostListener('keypress', ['$event'])
  onKeyPress(event: KeyboardEvent) {
    if (event.charCode < 48 || event.charCode > 57) {
      event.preventDefault();
    }
  }

  @HostListener('paste', ['$event'])
  onPaste(event: ClipboardEvent) {
    event.preventDefault(); // Detiene el pegado nativo

    const clipboardData = event.clipboardData || (window as any).clipboardData;
    const textoPegado = clipboardData?.getData('text') || '';

    // Extrae únicamente los caracteres que sean números
    const soloNumeros = textoPegado.replace(/[^0-9]/g, '');

    // Inserta manualmente el texto limpio
    if (soloNumeros.length > 0) {
      document.execCommand('insertText', false, soloNumeros);
    }
  }
}
