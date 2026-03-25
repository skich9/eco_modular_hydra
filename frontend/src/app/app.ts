import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { LoadingOverlayComponent } from './components/shared/loading-overlay/loading-overlay.component';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, LoadingOverlayComponent],
  template: `
    <div class="app-container">
      <main>
        <router-outlet></router-outlet>
      </main>
      <app-loading-overlay></app-loading-overlay>
    </div>
  `,
  styles: `
    :host {
      display: block;
      height: 100%;
    }
    .app-container {
      font-family: Arial, sans-serif;
      min-height: 100vh;
      height: 100%;
      display: flex;
      flex-direction: column;
    }
    .app-container > main {
      flex: 1;
      padding: 0;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }
    .app-container > main > app-layout {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
  `
})
export class App {}
