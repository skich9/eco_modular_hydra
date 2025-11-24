export const environment = {
    production: false,
    // Valor por defecto; en runtime el servicio ajustará host/puerto si está en navegador
    apiUrl: 'http://localhost:8069/api',
    apiPort: '8069',
    // URL base para generar códigos QR del SIN
    qrSinUrl: 'https://pilotosiat.impuestos.gob.bo/consulta/QR?',
};
