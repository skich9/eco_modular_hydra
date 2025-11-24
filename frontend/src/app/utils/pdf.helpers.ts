import { jsPDF } from 'jspdf';

export function saveBlobAsFile(blob: Blob, fileName: string): void {
	try {
		const url = URL.createObjectURL(blob);
		const a = document.createElement('a');
		a.href = url;
		a.download = fileName;
		document.body.appendChild(a);
		a.click();
		a.remove();
		URL.revokeObjectURL(url);
	} catch {}
}

export function generateQuickReciboPdf(params: { anio: number; nro: number; fecha?: string; codCeta?: string | number; total?: number; }): void {
	try {
		const { anio, nro } = params;
		const doc = new jsPDF();
		const fecha = params.fecha ? new Date(params.fecha).toLocaleString() : new Date().toLocaleString();
		const cod = params.codCeta ? String(params.codCeta) : '';
		const total = Number(params.total ?? 0);
		doc.setFontSize(14);
		doc.text('Recibo (generado rápido)', 14, 20);
		doc.setFontSize(11);
		doc.text(`N° E-${nro} / ${anio}`, 14, 30);
		doc.text(`Fecha: ${fecha}`, 14, 38);
		if (cod) doc.text(`Cod. CETA: ${cod}`, 14, 46);
		doc.text(`Total: Bs. ${total.toFixed(2)}`, 14, 54);
		doc.save(`recibo_${anio}_${nro}.pdf`);
	} catch {}
}

function literalBsParts(n: number): { literal: string; centavos: string } {
	n = isFinite(n) ? Math.abs(n) : 0;
	const entero = Math.floor(n);
	const cent = Math.round((n - entero) * 100) % 100;

	const unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
	const especiales = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
	const decenas = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
	const centenas = ['', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

	const toWords999 = (num: number): string => {
		if (num === 0) return '';
		const c = Math.floor(num / 100);
		const d = Math.floor((num % 100) / 10);
		const u = num % 10;
		let out = '';
		if (c) out += (c === 1 && (d || u) ? 'ciento' : centenas[c]) + (d || u ? ' ' : '');
		if (d === 1) {
			out += especiales[u];
		} else if (d === 2) {
			out += u === 0 ? 'veinte' : 'veinti' + unidades[u];
		} else {
			if (d) out += decenas[d] + (u ? ' y ' : '');
			if (u) out += (u === 1 ? 'un' : unidades[u]);
		}
		return out.trim();
	};

	const seccion = (num: number, divisor: number, singular: string, plural: string): string => {
		const cant = Math.floor(num / divisor);
		if (!cant) return '';
		const resto = num % divisor;
		let s = '';
		if (divisor === 1000000) {
			s = cant === 1 ? 'un millón' : `${toWords999(Math.floor(cant / 1000))}${cant >= 1000 ? ' mil ' : ''}${toWords999(cant % 1000)} millones`.replace(/\s+/g, ' ').trim();
		} else {
			s = cant === 1 ? singular : `${toWords999(cant)} ${plural}`;
		}
		return s + (resto ? ' ' : '');
	};

	const miles = (num: number): string => {
		const m = Math.floor(num / 1000);
		const r = num % 1000;
		let out = '';
		if (m) out += (m === 1 ? 'mil' : `${toWords999(m)} mil`) + (r ? ' ' : '');
		out += toWords999(r);
		return out.trim();
	};

	let literal = '';
	const millones = Math.floor(entero / 1000000);
	const restoMill = entero % 1000000;
	if (millones) {
		literal += millones === 1 ? 'un millón' : `${miles(millones)} millones`;
		if (restoMill) literal += ' ' + miles(restoMill);
	} else {
		literal = miles(entero);
	}
	if (!literal) literal = 'cero';

	return { literal: literal.toUpperCase(), centavos: cent.toString().padStart(2, '0') };
}

function normalizeLegend(s?: string): string {
    try {
        const t = (s ?? '').toString().trim();
        return t
            .replace(/[“”]/g, '"')
            .replace(/[’]/g, "'")
            .replace(/\s+/g, ' ')
            .replace(/[\.。]+$/g, '')
            .toUpperCase();
    } catch { return ''; }
}

function measureRoll80Height(params: any): number {
    try {
        const temp = new jsPDF({ unit: 'mm', format: [80, 2000] });
        const pageWidth = (temp as any).internal.pageSize.getWidth();
        const marginX = 5;
        const maxW = pageWidth - (marginX * 2);
        let y = 10;
        const incText = (n = 1) => { y += 5 * n; };
        const incLine = () => { y += 6; };
        const countWrap = (s: string) => {
            const lines = (temp as any).splitTextToSize((s ?? '').toString(), maxW) as string[];
            incText(lines.length);
        };
        incText();
        countWrap('CON DERECHO A CRÉDITO FISCAL');
        countWrap('INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L');
        const sucursalNum = String(params.sucursal ?? params.codigoSucursal ?? '1');
        const textoSucursalAuto = (sucursalNum === '0') ? 'CASA MATRIZ' : `SUCURSAL N. ${sucursalNum}`;
        const textoSucursal = (params.textoSucursal || textoSucursalAuto);
        countWrap(textoSucursal);
        const puntoVenta = String(params.puntoVenta ?? params.codigoPuntoVenta ?? '0');
        countWrap(`No. Punto de Venta ${puntoVenta}`);
        if (params.lineaZonaDireccion) countWrap(params.lineaZonaDireccion);
        const direccion = params.direccion || 'CALLE SAN ALBERTO NRO. 124';
        countWrap(direccion);
        const ciudad = params.municipioNombre || params.ciudad || 'COCHABAMBA';
        const telefono = params.telefono ? `   Tel. ${params.telefono}` : '';
        countWrap(`ZONA/BARRIO: ${ciudad}${telefono}`);
        countWrap(ciudad);
        incLine();
        countWrap('NIT');
        countWrap(String(params.nit ?? ''));
        countWrap('FACTURA N°');
        countWrap(String(params.numeroFactura ?? params.nro ?? ''));
        const codAut = params.codAutorizacion || params.cuf || '';
        if (codAut) { countWrap('CÓD. AUTORIZACIÓN'); countWrap(String(codAut)); }
        incLine();
        const complemento = params.complemento ? String(params.complemento) : '';
        const compFmt = complemento && complemento.length > 0 ? `-${complemento}` : '';
        const pairs: Array<{ label: string; value: string }> = [
            { label: 'NOMBRE/RAZÓN SOCIAL:', value: `${params.razon || 'S/N'}` },
            { label: 'NIT/CI/CEX:', value: `${params.codigoCliente || ''}${compFmt}` },
            { label: 'COD. CLIENTE:', value: `${params.codigoCliente || ''}${compFmt}` },
            { label: 'FECHA DE EMISIÓN:', value: `${params.fechaEmision}` },
        ];
        const periodoFact = params.periodo || params.periodoFacturado || '';
        if (periodoFact) pairs.push({ label: 'PERÍODO FACTURADO:', value: `${periodoFact}` });
        if (params.nombreEstudiante) pairs.push({ label: 'NOMBRE ESTUDIANTE:', value: `${params.nombreEstudiante}` });
        for (const _ of pairs) incText();
        incLine();
        incText();
        if (params.items && params.items.length) {
            for (const it of params.items) {
                const code = String(it.codigoProducto || '').trim();
                const firstLine = code ? `${code} - ${it.descripcion}` : `${it.descripcion}`;
                countWrap(firstLine);
                incText();
                incText();
            }
        } else {
            incText();
            incText();
            incText();
        }
        incLine();
        incText(6);
        try {
            const items = Array.isArray(params.items) ? params.items : [];
            let subRaw = 0, descItems = 0;
            if (items.length) {
                for (const it of items) {
                    subRaw += Number(it.cantidad || 0) * Number(it.precioUnitario || 0);
                    descItems += Number(it.montoDescuento || 0);
                }
            } else {
                subRaw = Number(params.cantidad || 0) * Number(params.pu || 0);
                descItems = Number(params.descuento || params.descuentoAdicional || 0);
            }
            const total = Math.max(0, +(subRaw - descItems).toFixed(2));
            const partes = literalBsParts(total);
            countWrap(`Son: ${partes.literal} ${partes.centavos}/100`);
            countWrap('Bolivianos');
        } catch { incText(2); }
        incLine();
        countWrap('ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS. EL USO ILÍCITO SERÁ SANCIONADO');
        countWrap('PENALMENTE DE ACUERDO A LEY');
        const fixedLegend = '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”';
        const l1 = normalizeLegend(params.leyenda);
        const l2 = normalizeLegend(params.leyenda2);
        const lfixed = normalizeLegend(fixedLegend);
        if (l1) countWrap(params.leyenda);
        countWrap(fixedLegend);
        if (l2 && l2 !== l1 && l2 !== lfixed) countWrap(params.leyenda2);
        if (params.qrBase64) { y += 4 + 40 + 4; }
        incLine();
        if (params.usuarioHora) incText();
        if (params.codCeta !== undefined && params.codCeta !== null) incText();
        return Math.ceil(y + 10);
    } catch {
        const itemsLen = Array.isArray((params as any).items) ? ((params as any).items as any[]).length : 0;
        const hasQr = !!(params as any).qrBase64;
        return Math.max(220, 130 + (itemsLen * 25) + (hasQr ? 60 : 0));
    }
}

export function generateQuickFacturaPdf(params: {
	anio: number;
	nro: number;
	razon: string;
	nit: string;
	codigoCliente: string;
	fechaEmision: string;
	periodo?: string;
	nombreEstudiante?: string;
	detalle: string;
	cantidad: number;
	pu: number;
	descuento?: number;
	montoGift?: number;
	total: number;
	importeBase: number;
	usuarioHora?: string;
	qrBase64?: string | null;
	sucursal?: string;
	puntoVenta?: string;
	direccion?: string;
	telefono?: string;
	ciudad?: string;
	codAutorizacion?: string;
	// Campos adicionales para compatibilidad con backend
	numeroFactura?: string | number;
	cuf?: string;
	codigoSucursal?: string | number;
	codigoPuntoVenta?: string | number;
	complemento?: string | number;
	periodoFacturado?: string;
	descuentoAdicional?: number;
	montoGiftCard?: number;
	montoTotal?: number;
	montoTotalSujetoIva?: number;
	items?: Array<{ codigoProducto: string; descripcion: string; nombreUnidadMedida: string; cantidad: number; precioUnitario: number; montoDescuento: number; subTotal: number; }>; 
	leyenda?: string;
	leyenda2?: string;
	totalTexto?: string;
	codCeta?: string | number;
	formato?: 'a4' | 'roll80';
	// overrides opcionales para encabezado
	textoSucursal?: string; // si viene, reemplaza a 'CASA MATRIZ' o 'SUCURSAL N. X'
	lineaZonaDireccion?: string; // línea libre para zona/dirección extendida
	municipioNombre?: string; // última línea (ciudad/municipio)
}): void {
    try {
        const isRoll = (params as any).formato === 'roll80';
        const doc = isRoll ? new jsPDF({ unit: 'mm', format: [80, Math.max(220, measureRoll80Height(params))] }) : new jsPDF({ unit: 'pt', format: 'a4' });
        let y = isRoll ? 10 : 40;
        const marginX = isRoll ? 5 : 40;
        const lineStr = isRoll ? '--------------------------------' : '---------------------------------------------------------------------';
        const pageHeight = (doc as any).internal.pageSize.getHeight();
        const pageWidth = (doc as any).internal.pageSize.getWidth();
        // Para A4, agregamos un acolchado adicional interno para evitar desborde en un solo lado
        const a4Safety = 200; // reducción más gruesa
        const sidePad = isRoll ? 0 : 24; // acolchado fino a cada lado
        const contentW = pageWidth - (marginX * 2) - (isRoll ? 0 : a4Safety) - (sidePad * 2);
        const innerPad = isRoll ? 0 : 8; // acolchado interno adicional simétrico
        const safeW = Math.max(10, contentW - (innerPad * 2));
		// En roll80 no forzamos saltos de página; para A4 sí
		const ensureSpace = () => { if (!isRoll && y > pageHeight - 40) { doc.addPage(); y = 40; } };
		const line = () => {
			try {
				(doc as any).setLineDash([1, 1], 0);
				// Dibujar la línea con los MISMO márgenes que el texto
				doc.line(marginX + sidePad, y, marginX + sidePad + contentW, y);
				(doc as any).setLineDash([]);
			} catch {
				doc.text(lineStr, marginX + sidePad, y);
			}
			y += isRoll ? 6 : 18; ensureSpace();
		};
		const text = (s: string) => {
			const val = (s ?? '').toString();
			const lines = (doc as any).splitTextToSize(val, Math.max(10, contentW - 12)) as string[];
			for (const ln of lines) {
				doc.text(ln, marginX + sidePad, y);
				y += isRoll ? 5 : 16; ensureSpace();
			}
		};
		const center = (s: string) => {
			// Centrado manual dentro de contentW con reducción de fuente si es necesario
			const txt = String(s ?? '').replace(/\s+/g, ' ');
			const prev = (doc as any).getFontSize ? doc.getFontSize() : (isRoll ? 9 : 11);
			let fs = prev;
			const minFs = isRoll ? 4 : 6;
			while (doc.getTextWidth(txt) > contentW - 2 && fs > minFs) { fs -= 0.25; doc.setFontSize(fs); }
			const w = doc.getTextWidth(txt);
			const left = marginX + sidePad;
			const right = left + contentW;
			let x = left + Math.max(0, (contentW - w) / 2);
			if (x + w > right) x = Math.max(left, right - w);
			if (x < left) x = left;
			doc.text(txt, x, y);
			doc.setFontSize(prev);
			y += isRoll ? 5 : 16; ensureSpace();
		};
		const wrapCenter = (s: string, upper = false) => {
			const raw = (s ?? '').toString();
			const val = upper ? raw.toUpperCase() : raw;
			const lines = (doc as any).splitTextToSize(val, Math.max(10, contentW - 12));
			for (const ln of lines) {
				const prev = (doc as any).getFontSize ? doc.getFontSize() : (isRoll ? 9 : 11);
				let fs = prev;
				while (doc.getTextWidth(ln) > contentW - 8 && fs > 6) { fs -= 0.5; doc.setFontSize(fs); }
				center(ln);
				doc.setFontSize(prev);
			}
		};
		const wrapLeft = (s: string, upper = false) => {
			const raw = (s ?? '').toString();
			const val = upper ? raw.toUpperCase() : raw;
			const lines = (doc as any).splitTextToSize(val, Math.max(10, contentW - 12));
			for (const ln of lines) {
				const prev = (doc as any).getFontSize ? doc.getFontSize() : (isRoll ? 9 : 11);
				let fs = prev;
				while (doc.getTextWidth(ln) > contentW - 8 && fs > 6) { fs -= 0.5; doc.setFontSize(fs); }
				text(ln);
				doc.setFontSize(prev);
			}
		};
		const wrapCenterLegend = (s: string, upper = false) => {
			const raw = (s ?? '').toString();
			const val = upper ? raw.toUpperCase() : raw;
			const legendW = Math.max(10, contentW - 100);
			const lines = (doc as any).splitTextToSize(val, legendW) as string[];
			for (const ln of lines) {
				doc.setFont('helvetica','normal');
				const prev = (doc as any).getFontSize ? doc.getFontSize() : 11;
				let fs = prev;
				while (doc.getTextWidth(ln) > legendW - 2 && fs > 6) { fs -= 0.5; doc.setFontSize(fs); }
				center(ln);
				doc.setFontSize(prev);
			}
		};
		const printLegendBlock = (arr: string[]) => {
			const linesRaw = (arr || []).filter(Boolean).map(v => String(v));
			if (!linesRaw.length) return;
			doc.setFont('helvetica','normal');
			const prev = (doc as any).getFontSize ? doc.getFontSize() : 11;
			let fs = 4.5;
			doc.setFontSize(fs);
			const lh = 10;
			// Usar un ancho EXTREMADAMENTE conservador
			const safeLeft = marginX + sidePad + 20;
			const safeRight = pageWidth - marginX - sidePad - 20;
			const maxW = Math.max(50, safeRight - safeLeft);
			
			// Función para dividir texto palabra por palabra respetando maxW
			const manualWrap = (text: string): string[] => {
				const words = text.split(' ');
				const result: string[] = [];
				let currentLine = '';
				
				for (const word of words) {
					const testLine = currentLine ? `${currentLine} ${word}` : word;
					const testWidth = doc.getTextWidth(testLine);
					
					if (testWidth <= maxW) {
						currentLine = testLine;
					} else {
						if (currentLine) result.push(currentLine);
						currentLine = word;
						// Si una sola palabra excede maxW, forzar salto
						if (doc.getTextWidth(word) > maxW) {
							result.push(word);
							currentLine = '';
						}
					}
				}
				if (currentLine) result.push(currentLine);
				return result;
			};
			
			// Procesar cada texto
			for (const t of linesRaw) {
				const wrappedLines = manualWrap(t);
				// Reducir fuente si alguna línea aún excede
				for (const ln of wrappedLines) {
					while (doc.getTextWidth(ln) > maxW && fs > 4.0) {
						fs -= 0.25;
						doc.setFontSize(fs);
					}
				}
				// Imprimir cada línea centrada manualmente con límites estrictos
				for (const ln of wrappedLines) {
					const w = doc.getTextWidth(ln);
					const xCenter = safeLeft + (maxW / 2);
					let x = xCenter - (w / 2);
					// Clamp ESTRICTO: nunca permitir que se salga
					if (x < safeLeft) x = safeLeft;
					if (x + w > safeRight) x = safeRight - w;
					if (x < safeLeft) x = safeLeft; // doble check
					doc.text(ln, x, y);
					y += lh;
					ensureSpace();
				}
			}
			doc.setFontSize(prev);
		};
		const labelValueOneLineCentered = (label: string, value: string, override?: { fsLabel: number; fsValue: number }) => {
			// Una sola línea centrada: etiqueta en negrita + valor normal, ambos mayúscula.
			const lbl = String(label ?? '').toUpperCase();
			const val = String(value ?? '').toUpperCase();
			const maxWidth = contentW;
			const base = (doc as any).internal.getFontSize ? (doc as any).internal.getFontSize() : (isRoll ? 9 : 11);
			let fsLabel = override ? override.fsLabel : Math.max(5, base - 2);
			let fsValue = override ? override.fsValue : Math.max(5, base - 2);
			const measure = () => {
				doc.setFont('helvetica', 'bold'); doc.setFontSize(fsLabel);
				const wLabel = doc.getTextWidth(lbl + ' ');
				doc.setFont('helvetica', 'normal'); doc.setFontSize(fsValue);
				const wValue = doc.getTextWidth(val);
				return { wLabel, wValue, total: wLabel + wValue };
			};
			let m = measure();
			if (!override) {
				while (m.total > maxWidth && (fsLabel > 5 || fsValue > 5)) {
					fsLabel = Math.max(5, fsLabel - 0.5);
					fsValue = Math.max(5, fsValue - 0.5);
					m = measure();
				}
			}
			let x = marginX + sidePad + Math.max(0, (contentW - m.total) / 2);
			doc.setFont('helvetica', 'bold'); doc.setFontSize(fsLabel);
			doc.text(lbl + ' ', x, y);
			x += m.wLabel;
			doc.setFont('helvetica', 'normal'); doc.setFontSize(fsValue);
			doc.text(val, x, y);
			y += isRoll ? 5 : 16; ensureSpace();
		};

        // Derivar valores y compatibilidades
        const sucursalNum = String(params.sucursal ?? params.codigoSucursal ?? '1');
        const textoSucursalAuto = (sucursalNum === '0') ? 'CASA MATRIZ' : `SUCURSAL N. ${sucursalNum}`;
        const textoSucursal = (params.textoSucursal || textoSucursalAuto);
        const puntoVenta = String(params.puntoVenta ?? params.codigoPuntoVenta ?? '0');
        const direccion = params.direccion || 'CALLE SAN ALBERTO NRO. 124';
        const ciudad = params.municipioNombre || params.ciudad || 'COCHABAMBA';
        const telefono = params.telefono ? `   Tel. ${params.telefono}` : '';
        const numeroFactura = String(params.numeroFactura ?? params.nro);
        const codAut = params.codAutorizacion || params.cuf || '';
        const periodoFact = params.periodo || params.periodoFacturado || '';
        const complemento = params.complemento ? String(params.complemento) : '';
        const compFmt = complemento && complemento.length > 0 ? `-${complemento}` : '';

        // Encabezado (solo FACTURA y CON DERECHO... en negrita; resto en mayúsculas normal)
        doc.setFontSize(isRoll ? 12 : 13);
        if (isRoll) { doc.setFont('helvetica', 'bold'); wrapCenter('FACTURA', true); } else { doc.text('FACTURA', marginX, y); y += 18; }
        doc.setFontSize(isRoll ? 9 : 11);
        if (isRoll) {
            doc.setFont('helvetica', 'bold'); wrapCenter('CON DERECHO A CRÉDITO FISCAL', true);
            doc.setFont('helvetica', 'normal');
            wrapCenter('INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L', true);
            wrapCenter(textoSucursal, true);
            wrapCenter(`No. Punto de Venta ${puntoVenta}`, true);
            if (params.lineaZonaDireccion) wrapCenter(params.lineaZonaDireccion, true);
            wrapCenter(direccion, true);
            wrapCenter(`ZONA/BARRIO: ${ciudad}${telefono}`, true);
            wrapCenter(ciudad, true);
        } else {
            wrapLeft('CON DERECHO A CRÉDITO FISCAL', true);
            wrapLeft('INSTITUTO TECNOLOGICO DE ENSEÑANZA AUTOMOTRIZ "CETA" S.R.L', true);
            wrapLeft(textoSucursal, true);
            wrapLeft(`No. Punto de Venta ${puntoVenta}`, true);
            wrapLeft(direccion, true);
            wrapLeft(`ZONA/BARRIO: ${ciudad}${telefono}`, true);
            wrapLeft(ciudad, true);
        }
        line();

        // NIT y factura (labels en negrita, valores normales)
        if (isRoll) {
            doc.setFont('helvetica', 'bold'); wrapCenter('NIT', true); doc.setFont('helvetica', 'normal'); wrapCenter(`${params.nit}`, true);
            doc.setFont('helvetica', 'bold'); wrapCenter('FACTURA N°', true); doc.setFont('helvetica', 'normal'); wrapCenter(`${numeroFactura}`, true);
            if (codAut) { doc.setFont('helvetica', 'bold'); wrapCenter('CÓD. AUTORIZACIÓN', true); doc.setFont('helvetica', 'normal'); wrapCenter(String(codAut), true); }
        } else {
            text(`NIT ${params.nit}`);
            text(`FACTURA N° ${numeroFactura}`);
            if (codAut) text(`CÓD. AUTORIZACIÓN ${codAut}`);
        }
        line();

        // Cliente (una sola línea por campo). Calcular tamaño común que quepa para todas las líneas.
        const baseFs = (doc as any).internal.getFontSize ? (doc as any).internal.getFontSize() : (isRoll ? 9 : 11);
        const pairs: Array<{ label: string; value: string }> = [
            { label: 'NOMBRE/RAZÓN SOCIAL:', value: `${params.razon || 'S/N'}` },
            { label: 'NIT/CI/CEX:', value: `${params.codigoCliente || ''}${compFmt}` },
            { label: 'COD. CLIENTE:', value: `${params.codigoCliente || ''}${compFmt}` },
            { label: 'FECHA DE EMISIÓN:', value: `${params.fechaEmision}` },
        ];
        if (periodoFact) pairs.push({ label: 'PERÍODO FACTURADO:', value: `${periodoFact}` });
        if (params.nombreEstudiante) pairs.push({ label: 'NOMBRE ESTUDIANTE:', value: `${params.nombreEstudiante}` });
        const maxWidth = contentW;
        let fsTargetLabel = Math.max(5, baseFs - 2);
        let fsTargetValue = Math.max(5, baseFs - 2);
        const fitFor = (label: string, value: string) => {
            const lbl = String(label ?? '').toUpperCase();
            const val = String(value ?? '').toUpperCase();
            let fl = fsTargetLabel, fv = fsTargetValue;
            const measure = () => {
                doc.setFont('helvetica', 'bold'); doc.setFontSize(fl);
                const wLabel = doc.getTextWidth(lbl + ' ');
                doc.setFont('helvetica', 'normal'); doc.setFontSize(fv);
                const wValue = doc.getTextWidth(val);
                return wLabel + wValue;
            };
            let total = measure();
            while (total > maxWidth && (fl > 5 || fv > 5)) {
                fl = Math.max(5, fl - 0.5);
                fv = Math.max(5, fv - 0.5);
                total = measure();
            }
            return { fl, fv };
        };
        for (const p of pairs) {
            const r = fitFor(p.label, p.value);
            fsTargetLabel = Math.min(fsTargetLabel, r.fl);
            fsTargetValue = Math.min(fsTargetValue, r.fv);
        }
        for (const p of pairs) {
            labelValueOneLineCentered(p.label, p.value, { fsLabel: fsTargetLabel, fsValue: fsTargetValue });
        }
        line();

        // Detalle
        doc.setFont('helvetica', 'bold');
        if (isRoll) { wrapCenter('DETALLE', true); } else { text('DETALLE'); }
        doc.setFont('helvetica', 'normal');
        if (isRoll) { doc.setFontSize(8); }

        let subtotalCalc = 0;
        if (params.items && params.items.length) {
            for (const it of params.items) {
                doc.setFont('helvetica', 'bold');
                const code = String(it.codigoProducto || '').trim();
                const firstLine = code ? `${code} - ${it.descripcion}` : `${it.descripcion}`;
                wrapLeft(firstLine, false);
                doc.setFont('helvetica', 'normal');
                text(`Unidad de medida: ${it.nombreUnidadMedida}`);
                const cantStr = Number(it.cantidad || 0).toFixed(2);
                const puStr = Number(it.precioUnitario || 0).toFixed(2);
                const descStr = Number(it.montoDescuento || 0).toFixed(2);
                const subStr = Number(it.subTotal || 0).toFixed(2);
                if (isRoll) {
                    doc.text(`${cantStr} X ${puStr} - ${descStr}`, marginX, y);
                    doc.text(subStr, pageWidth - marginX, y, { align: 'right' as any });
                    y += 5; ensureSpace();
                } else {
                    const leftStr = `${cantStr} X ${puStr} - ${descStr}`;
                    doc.text(leftStr, marginX, y);
                    doc.text(subStr, pageWidth - marginX - sidePad, y, { align: 'right' as any });
                    y += isRoll ? 5 : 16; ensureSpace();
                }
                subtotalCalc += Number(it.subTotal || 0);
            }
        } else {
            text(`${params.detalle}`);
            text('Unidad de medida: UNIDAD (SERVICIOS)');
            const cantNum = Number(params.cantidad || 1);
            const puNum = Number(params.pu || 0);
            const descNum = Number(params.descuento || 0);
            const importeNum = Number(params.total || (cantNum * puNum - descNum));
            const cant = cantNum.toFixed(2);
            const pu = puNum.toFixed(2);
            const desc = descNum.toFixed(2);
            const importe = importeNum.toFixed(2);
            text(`${cant} X ${pu} - ${desc}    ${importe}`);
            subtotalCalc = cantNum * puNum;
        }
        line();

        // Descuento desde ítems; si no hay ítems, usar parámetros
        let descVal = 0;
        if (params.items && params.items.length) {
            for (const it of params.items) descVal += Number(it.montoDescuento || 0);
        } else {
            descVal = Number((params as any).descuento ?? params.descuentoAdicional ?? 0);
        }
        const giftVal = Number(params.montoGift ?? params.montoGiftCard ?? 0);
        const totalVal = Math.max(0, +(subtotalCalc - descVal).toFixed(2));
        const pagarVal = totalVal;
        const impBase = totalVal;

        if (isRoll) {
            doc.setFont('helvetica', 'bold'); doc.text('SUBTOTAL Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(subtotalCalc.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('DESCUENTO Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(descVal.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('TOTAL Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(totalVal.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('MONTO GIFT CARD Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(giftVal.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('MONTO A PAGAR Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(pagarVal.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('IMPORTE BASE CRÉDITO FISCAL', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(impBase.toFixed(2), pageWidth - marginX, y, { align: 'right' as any }); y += 5; ensureSpace();
        } else {
            doc.setFont('helvetica', 'bold'); doc.text('SUBTOTAL Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(subtotalCalc.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('DESCUENTO Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(descVal.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('TOTAL Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(totalVal.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('MONTO GIFT CARD Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(giftVal.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('MONTO A PAGAR Bs', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(pagarVal.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
            doc.setFont('helvetica', 'bold'); doc.text('IMPORTE BASE CRÉDITO FISCAL', marginX, y); doc.setFont('helvetica', 'normal'); doc.text(impBase.toFixed(2), pageWidth - marginX - sidePad, y, { align: 'right' as any }); y += 16; ensureSpace();
        }
        try {
            const n = Number(totalVal || 0);
            const partes = literalBsParts(n);
            if (isRoll) {
                wrapLeft(`Son: ${partes.literal} ${partes.centavos}/100`, false);
                wrapLeft('Bolivianos', false);
            } else {
                center(`Son: ${partes.literal} ${partes.centavos}/100`);
                center('Bolivianos');
            }
        } catch {}
        line();

        // Leyendas
        const fixedLegend = '"Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea"';
        const l1 = normalizeLegend(params.leyenda);
        const l2 = normalizeLegend(params.leyenda2);
        const lfixed = normalizeLegend(fixedLegend);
        if (isRoll) {
            doc.setFont('helvetica','normal');
            doc.setFontSize(7);
            const legendLines: string[] = [];
            legendLines.push('ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS. EL USO ILÍCITO SERÁ SANCIONADO');
            legendLines.push('PENALMENTE DE ACUERDO A LEY');
            if (l1) legendLines.push(String(params.leyenda));
            legendLines.push(fixedLegend);
            if (l2 && l2 !== l1 && l2 !== lfixed) legendLines.push(String(params.leyenda2));
            
            for (const legend of legendLines) {
                const wrapped = (doc as any).splitTextToSize(legend, contentW - 4) as string[];
                for (const line of wrapped) {
                    const w = doc.getTextWidth(line);
                    const left = marginX + sidePad;
                    const x = left + Math.max(0, (contentW - w) / 2);
                    doc.text(line, x, y);
                    y += 5;
                    ensureSpace();
                }
            }
        } else {
            const block: string[] = [];
            block.push('ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS. EL USO ILÍCITO SERÁ SANCIONADO');
            block.push('PENALMENTE DE ACUERDO A LEY');
            if (l1) block.push(String(params.leyenda));
            block.push(fixedLegend);
            if (l2 && l2 !== l1 && l2 !== lfixed) block.push(String(params.leyenda2));
            printLegendBlock(block);
        }

        // QR (si se provee base64)
        if (params.qrBase64) {
            try {
                y += isRoll ? 4 : 10;
                const size = isRoll ? 40 : 160;
                const pageWidth = (doc as any).internal.pageSize.getWidth();
                const x = isRoll
                    ? (pageWidth - size) / 2
                    : (marginX + sidePad + Math.max(0, (contentW - size) / 2));
                doc.addImage(params.qrBase64, 'PNG', x, y, size, size);
                y += size + (isRoll ? 4 : 10);
            } catch {}
        }

        // Pie
        line();
        const userLine = params.usuarioHora || '';
        if (userLine) { if (isRoll) text(userLine); else center(userLine); }
        if (params.codCeta !== undefined && params.codCeta !== null) { if (isRoll) text(`cod ceta: ${String(params.codCeta)}`); else center(`cod ceta: ${String(params.codCeta)}`); }

        doc.save(`factura_${params.anio}_${params.nro}.pdf`);
    } catch {}
}
