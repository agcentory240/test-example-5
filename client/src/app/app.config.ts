import { ApplicationConfig } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideStore } from '@ngrx/store';
import { provideEffects } from '@ngrx/effects';
import { providePrimeNG } from 'primeng/config';
import { MessageService } from 'primeng/api';
import Aura from '@primeuix/themes/aura';
import { routes } from './app.routes';
import { httpErrorInterceptor } from './interceptors/http-error.interceptor';
import { productsReducer } from './store/products/products.reducer';
import { ProductsEffects } from './store/products/products.effects';

export const appConfig: ApplicationConfig = {
  providers: [
    provideRouter(routes),
    provideHttpClient(withInterceptors([httpErrorInterceptor])),
    provideStore({ products: productsReducer }),
    provideEffects([ProductsEffects]),
    MessageService,
    providePrimeNG({
      theme: {
        preset: Aura
      }
    })
  ]
};
