import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'products',
  },
  {
    path: 'products',
    loadComponent: () =>
      import('./pages/products/products-page.component').then(
        (m) => m.ProductsPageComponent,
      ),
  },
  {
    path: '**',
    redirectTo: 'products',
  },
];
