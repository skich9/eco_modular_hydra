import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: `
    <div class="app-container">
      <header>
        <h1>Laravel Angular App</h1>
      </header>
      
      <main>
        <router-outlet></router-outlet>
      </main>
      
      <footer>
        <p>&copy; 2025 - Laravel + Angular Integration</p>
      </footer>
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
      padding: 2rem;
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
