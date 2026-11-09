import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { ImportDrawerComponent } from '../import-drawer/import-drawer.component';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink, ButtonModule, ImportDrawerComponent],
  template: `
    <header class="app-header">
      <a routerLink="/products" class="app-header__link">Список товаров</a>
      <button pButton type="button" label="Импорт" (click)="drawerVisible = true"></button>
    </header>

    <app-import-drawer [(visible)]="drawerVisible" />
  `,
  styles: [
    `
      .app-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1.5rem;
        border-bottom: 1px solid var(--p-content-border-color, #e5e7eb);
        background: var(--p-content-background, #fff);
      }

      .app-header__link {
        font-weight: 600;
        text-decoration: none;
        color: var(--p-text-color, #111827);
      }
    `,
  ],
})
export class HeaderComponent {
  drawerVisible = false;
}
