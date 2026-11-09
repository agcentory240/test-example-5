import { Injectable, inject } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { of } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';
import { ProductService } from '../../services/product.service';
import { ProductsActions } from './products.actions';

@Injectable()
export class ProductsEffects {
  private readonly actions$ = inject(Actions);
  private readonly productService = inject(ProductService);

  loadProducts$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ProductsActions.loadProducts),
      switchMap(({ query }) =>
        this.productService.getProducts(query).pipe(
          map((response) =>
            ProductsActions.loadProductsSuccess({ products: response.data, meta: response.meta }),
          ),
          catchError((error: unknown) =>
            of(
              ProductsActions.loadProductsFailure({
                error: error instanceof Error ? error.message : 'Ошибка загрузки',
              }),
            ),
          ),
        ),
      ),
    ),
  );
}
