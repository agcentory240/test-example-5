import { AsyncPipe, DatePipe, DecimalPipe } from '@angular/common';
import { Component, DestroyRef, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Store } from '@ngrx/store';
import { Subject, debounceTime } from 'rxjs';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ButtonModule } from 'primeng/button';
import { InputNumberModule } from 'primeng/inputnumber';
import { InputTextModule } from 'primeng/inputtext';
import { TableLazyLoadEvent, TableModule, TableRowSelectEvent } from 'primeng/table';
import { Product } from '../../models/product.model';
import { ProductQuery, ProductSortField, ProductSortOrder } from '../../models/product-query.model';
import { ProductService } from '../../services/product.service';
import { ProductsActions } from '../../store/products/products.actions';
import {
  selectAllProducts,
  selectProductsLoading,
  selectProductsMeta,
} from '../../store/products/products.selectors';
import { initialProductsState } from '../../store/products/products.state';
import { ProductDetailDialogComponent } from '../../components/product-detail-dialog/product-detail-dialog.component';

@Component({
  selector: 'app-products-page',
  standalone: true,
  imports: [
    AsyncPipe,
    DatePipe,
    DecimalPipe,
    FormsModule,
    TableModule,
    ButtonModule,
    InputNumberModule,
    InputTextModule,
    ProductDetailDialogComponent,
  ],
  templateUrl: './products-page.component.html',
  styles: [
    `
      .products-page {
        padding: 1.5rem;
      }

      .products-page__filters {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
        margin-bottom: 1rem;
      }

      .products-page__filters input {
        width: 100%;
      }

      .products-page__search {
        flex: 1 1 240px;
      }

      .products-page__price {
        flex: 0 0 160px;
      }

      @media (min-width: 768px) {
        .products-page__filters input {
          width: auto;
        }

        .products-page__search input {
          width: 100%;
        }
      }
    `,
  ],
})
export class ProductsPageComponent {
  private readonly store = inject(Store);
  private readonly productService = inject(ProductService);
  private readonly destroyRef = inject(DestroyRef);

  private readonly filterChanges$ = new Subject<Partial<ProductQuery>>();

  readonly products$ = this.store.select(selectAllProducts);
  readonly meta$ = this.store.select(selectProductsMeta);
  readonly loading$ = this.store.select(selectProductsLoading);

  filterName = '';
  priceMin: number | null = null;
  priceMax: number | null = null;

  selectedProduct: Product | null = null;
  dialogProduct: Product | null = null;
  dialogVisible = false;

  private currentQuery: ProductQuery = { ...initialProductsState.query };
  private lastRequestedKey: string | null = null;

  constructor() {
    this.filterChanges$
      .pipe(debounceTime(300), takeUntilDestroyed())
      .subscribe((patch) => this.requestQuery({ ...patch, page: 1 }));
  }

  onNameChange(value: string): void {
    this.filterName = value;
    this.filterChanges$.next(this.currentFilters());
  }

  onPriceMinChange(value: number | null): void {
    this.priceMin = value;
    this.filterChanges$.next(this.currentFilters());
  }

  onPriceMaxChange(value: number | null): void {
    this.priceMax = value;
    this.filterChanges$.next(this.currentFilters());
  }

  resetFilters(): void {
    this.filterName = '';
    this.priceMin = null;
    this.priceMax = null;
    this.requestQuery({
      ...initialProductsState.query,
      page: 1,
      name: undefined,
      price_min: undefined,
      price_max: undefined,
    });
  }

  onLazyLoad(event: TableLazyLoadEvent): void {
    const limit = event.rows ?? this.currentQuery.limit ?? 10;
    const first = event.first ?? 0;
    const page = Math.floor(first / limit) + 1;
    const sort = (event.sortField as ProductSortField | undefined) ?? this.currentQuery.sort ?? 'id';
    const order: ProductSortOrder = (event.sortOrder ?? 1) === -1 ? 'desc' : 'asc';

    this.requestQuery({ page, limit, sort, order });
  }

  onRowSelect(event: TableRowSelectEvent<Product>): void {
    const selected = event.data as Product | undefined;

    if (!selected) {
      return;
    }

    this.selectedProduct = selected;
    this.dialogProduct = selected;
    this.dialogVisible = true;

    // Список отдаёт только summary (без атрибутов) — догружаем карточку.
    this.productService
      .getProduct(selected.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (product) => {
          if (this.selectedProduct?.id === product.id) {
            this.dialogProduct = product;
          }
        },
        error: () => {
          // Оставляем summary-данные.
        },
      });
  }

  private currentFilters(): Partial<ProductQuery> {
    const name = this.filterName.trim();

    return {
      name: name === '' ? undefined : name,
      price_min: this.priceMin ?? undefined,
      price_max: this.priceMax ?? undefined,
    };
  }

  private requestQuery(patch: Partial<ProductQuery>): void {
    this.currentQuery = { ...this.currentQuery, ...patch };

    if (
      this.currentQuery.price_min !== undefined &&
      this.currentQuery.price_max !== undefined &&
      this.currentQuery.price_min > this.currentQuery.price_max
    ) {
      return;
    }

    const query: ProductQuery = { ...this.currentQuery };
    const key = JSON.stringify(query);

    if (key === this.lastRequestedKey) {
      return;
    }

    this.lastRequestedKey = key;
    this.store.dispatch(ProductsActions.loadProducts({ query }));
  }
}
