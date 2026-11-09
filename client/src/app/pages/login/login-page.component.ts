import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { PasswordModule } from 'primeng/password';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-login-page',
  standalone: true,
  imports: [FormsModule, ButtonModule, InputTextModule, PasswordModule],
  template: `
    <div class="login-page">
      <form class="login-card" (ngSubmit)="submit()">
        <h1 class="login-card__title">Вход</h1>

        <label class="login-card__field">
          <span>Email</span>
          <input pInputText type="email" name="email" [(ngModel)]="email" autocomplete="username" />
        </label>

        <label class="login-card__field">
          <span>Пароль</span>
          <p-password
            name="password"
            [(ngModel)]="password"
            [feedback]="false"
            [toggleMask]="true"
            autocomplete="current-password"
            styleClass="w-full"
            inputStyleClass="w-full"
          />
        </label>

        @if (error()) {
          <p class="login-card__error">{{ error() }}</p>
        }

        <button pButton type="submit" label="Войти" [loading]="loading()" [disabled]="loading()"></button>
      </form>
    </div>
  `,
  styles: [
    `
      .login-page {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 1rem;
        background: var(--p-content-background, #f9fafb);
      }

      .login-card {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        width: 100%;
        max-width: 360px;
        padding: 2rem;
        border: 1px solid var(--p-content-border-color, #e5e7eb);
        border-radius: 0.75rem;
        background: var(--p-content-background, #fff);
      }

      .login-card__title {
        margin: 0 0 0.5rem;
        font-size: 1.25rem;
        font-weight: 600;
      }

      .login-card__field {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
        font-size: 0.875rem;
      }

      .login-card__error {
        margin: 0;
        color: var(--p-red-500, #ef4444);
        font-size: 0.875rem;
      }
    `,
  ],
})
export class LoginPageComponent {
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  email = '';
  password = '';
  readonly error = signal('');
  readonly loading = signal(false);

  submit(): void {
    const email = this.email.trim();

    if (email === '' || this.password === '') {
      this.error.set('Введите email и пароль');
      return;
    }

    this.loading.set(true);
    this.error.set('');

    this.authService.login(email, this.password).subscribe({
      next: () => {
        this.loading.set(false);
        void this.router.navigateByUrl('/products');
      },
      error: (error: { status?: number }) => {
        this.loading.set(false);
        this.error.set(
          error.status === 401 ? 'Неверный email или пароль' : 'Не удалось выполнить вход',
        );
      },
    });
  }
}
