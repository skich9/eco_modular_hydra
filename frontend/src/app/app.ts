import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: `
    <div class="app-container">
      <main>
        <router-outlet></router-outlet>
      </main>
    </div>
  `,
  styles: `
    .app-container {
      font-family: Arial, sans-serif;
    }
    header {
      background: #3f51b5;
      color: white;
      padding: 1rem;
      text-align: center;
    }
    main {
      padding: 0rem;
    }
    footer {
      background: #f5f5f5;
      padding: 1rem;
      text-align: center;
      font-size: 0.9rem;
      color: #666;
      position: fixed;
      bottom: 0;
      width: 100%;
    }
  `
})
export class App {}
