import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { registerLocaleData } from '@angular/common';
import es from '@angular/common/locales/es';

// Registrar locale español para pipes de fecha/número
registerLocaleData(es);

bootstrapApplication(App, appConfig)
  .catch((err) => console.error(err));
