import { Component, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { Store } from '@ngrx/store';
import { ConfirmationService, MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ImportDrawerComponent } from '../import-drawer/import-drawer.component';
import { AuthService } from '../../services/auth.service';
import { ProductService } from '../../services/product.service';
import { ProductsActions } from '../../store/products/products.actions';
import { initialProductsState } from '../../store/products/products.state';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink, ButtonModule, ImportDrawerComponent],
  template: `
    <header class="app-header">
      <a routerLink="/products" class="app-header__link">Список товаров</a>

      <div class="app-header__actions">
        <button
          pButton
          type="button"
          label="Удалить все товары"
          icon="pi pi-trash"
          severity="danger"
          [text]="true"
          (click)="confirmClearAll()"
        ></button>
        <button pButton type="button" label="Импорт" (click)="drawerVisible = true"></button>
        <button
          pButton
          type="button"
          label="Выйти"
          icon="pi pi-sign-out"
          severity="secondary"
          [text]="true"
          (click)="logout()"
        ></button>
      </div>
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

      .app-header__actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
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
  private readonly confirmationService = inject(ConfirmationService);
  private readonly messageService = inject(MessageService);
  private readonly productService = inject(ProductService);
  private readonly store = inject(Store);
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  drawerVisible = false;

  logout(): void {
    this.authService.logout();
    void this.router.navigateByUrl('/login');
  }

  confirmClearAll(): void {
    this.confirmationService.confirm({
      message: 'Удалить все товары и файлы?',
      header: 'Подтверждение',
      icon: 'pi pi-exclamation-triangle',
      accept: () => this.clearAll(),
    });
  }

  clearAll(): void {
    this.productService.clearAll().subscribe({
      next: () => {
        this.store.dispatch(ProductsActions.loadProducts({ query: { ...initialProductsState.query } }));
        this.messageService.add({
          severity: 'success',
          summary: 'Удалено',
          detail: 'Все товары и файлы удалены',
          life: 3000,
        });
      },
      error: () => {
        this.messageService.add({
          severity: 'error',
          summary: 'Ошибка',
          detail: 'Не удалось удалить товары',
          life: 3000,
        });
      },
    });
  }
}
