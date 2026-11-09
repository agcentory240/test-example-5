import { Injectable, inject } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { Store } from '@ngrx/store';
import { of } from 'rxjs';
import { catchError, map, switchMap, withLatestFrom } from 'rxjs/operators';
import { ProductService } from '../../services/product.service';
import { ProductsActions } from './products.actions';
import { selectProductsQuery } from './products.selectors';

@Injectable()
export class ProductsEffects {
  private readonly actions$ = inject(Actions);
  private readonly productService = inject(ProductService);
  private readonly store = inject(Store);

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

  /**
   * Импорт завершён (в том числе с ошибками) — перечитываем список
   * с текущими фильтрами и сортировкой, но с первой страницы:
   * новые товары появляются в начале списка.
   */
  importCompleted$ = createEffect(() =>
    this.actions$.pipe(
      ofType(ProductsActions.importCompleted),
      withLatestFrom(this.store.select(selectProductsQuery)),
      map(([, query]) => ProductsActions.loadProducts({ query: { ...query, page: 1 } })),
    ),
  );
}
