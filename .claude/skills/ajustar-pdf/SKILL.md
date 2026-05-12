---
name: ajustar-pdf
description: Usar cuando el usuario quiere modificar el visual de un PDF generado en sistemaEco. Activar con frases como "ajustar el PDF", "el footer no queda bien", "el header está muy grande", "cambiar estilos del PDF", "el PDF no se ve bien", "quiero que el PDF sea similar al SGA".
version: 1.0.0
---

# Skill: Ajustar PDF generado con mPDF

## Stack PDF del proyecto

- **Librería**: mPDF v8.3.1 (NO Dompdf)
- **Servicio PDF**: `src/app/Services/Economico/ReporteCajaFuertePdfService.php`
- **Blade (solo cuerpo)**: `src/resources/views/pdf/reporte_caja_fuerte.blade.php`
- **Formato**: Letter portrait, márgenes: left=8mm, right=8mm, top=42mm, bottom=42mm

## Cómo funciona mPDF en este proyecto

- `SetHTMLHeader($html)` → el HTML del header se repite automáticamente en CADA página
- `SetHTMLFooter($html)` → el HTML del footer se repite automáticamente en CADA página
- El Blade solo contiene la tabla de datos — sin lógica de paginación, sin spacers
- CSS se pasa via `WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS)`
- Número de página en el footer: `{PAGENO}` y `{nb}` directamente en el HTML

## Dónde hacer cada ajuste

| Qué ajustar | Dónde |
|-------------|-------|
| Tamaño de márgenes de página | Constructor `new Mpdf([...])` — `margin_top`, `margin_bottom`, `margin_left`, `margin_right` |
| Espacio entre borde de página y header/footer | `margin_header`, `margin_footer` en el constructor |
| Contenido del header (logo, institución, título) | Método `renderHeader()` del servicio PDF |
| Contenido del footer (firmas, usuario, fecha, página) | Método `renderFooter()` del servicio PDF |
| Estilos de la tabla de datos (colores, bordes, fuentes) | String `$css` dentro de `generar()` |
| Filas de la tabla (saldo anterior, movimientos, subtotales) | Blade template |
| Anchos de columnas | Clases `.col-num`, `.col-trans`, `.col-fecha`, `.col-ref`, `.col-monto` en el `$css` |

## Referencia de estilos SGA (objetivo visual)

- Bordes de celdas de datos: `border-width:1px; border-color:red; border-style:dotted`
- Encabezado tabla: `background-color:#d9edf7; color:#000066; font-weight:bold`
- Bordes header/footer institucional: `border-color:#000066; border-style:solid`
- Bordes footer firmas: `border-color:#cc0000; border-style:solid`
- Fuente: `Arial, sans-serif; font-size:9pt` para header/footer; `sans-serif; font-size:12px` para tabla

## Proceso para ajustar

1. Si el usuario manda una captura del PDF → analizar qué difiere visualmente
2. Identificar en qué método/variable del servicio está el elemento a cambiar
3. Modificar SOLO lo necesario
4. Pedir al usuario que regenere el PDF y confirme el resultado
5. Si el margen necesita ajuste fino: tocar `margin_top`/`margin_bottom` o `margin_header`/`margin_footer`

## NO hacer

- No volver a Dompdf
- No agregar paginación manual en Blade (`$rowsFirstPage`, spacers, `@foreach $paginas`)
- No usar `position: fixed` en el CSS (no funciona igual en mPDF que en navegadores)
