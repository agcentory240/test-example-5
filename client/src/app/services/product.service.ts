import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import { PaginatedResponse } from '../models/paginated-response.model';
import { Product, ProductAttribute } from '../models/product.model';
import { ProductQuery } from '../models/product-query.model';

@Injectable({ providedIn: 'root' })
export class ProductService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiUrl}/api/products`;

  getProducts(query: ProductQuery = {}): Observable<PaginatedResponse<Product>> {
    let params = new HttpParams();

    if (query.page !== undefined && query.page !== null) {
      params = params.set('page', query.page);
    }
    if (query.limit !== undefined && query.limit !== null) {
      params = params.set('limit', query.limit);
    }
    if (query.name !== undefined && query.name !== null && query.name.trim() !== '') {
      params = params.set('name', query.name.trim());
    }
    if (query.price_min !== undefined && query.price_min !== null) {
      params = params.set('price_min', query.price_min);
    }
    if (query.price_max !== undefined && query.price_max !== null) {
      params = params.set('price_max', query.price_max);
    }
    if (query.sort !== undefined && query.sort !== null) {
      params = params.set('sort', query.sort);
    }
    if (query.order !== undefined && query.order !== null) {
      params = params.set('order', query.order);
    }

    return this.http
      .get<PaginatedResponse<Product>>(this.baseUrl, { params })
      .pipe(
        map((response) => ({
          ...response,
          data: response.data.map((product) => this.normalize(product)),
        })),
      );
  }

  getProduct(id: number): Observable<Product> {
    return this.http
      .get<Product>(`${this.baseUrl}/${id}`)
      .pipe(map((product) => this.normalize(product)));
  }

  /**
   * Бэкенд отдаёт attributes в карточке как key-value-объект, а в списке не отдаёт вовсе.
   * Приводим к ProductAttribute[] и пустому массиву соответственно.
   */
  private normalize(product: Product): Product {
    return { ...product, attributes: this.toAttributes(product.attributes) };
  }

  private toAttributes(raw: unknown): ProductAttribute[] {
    if (Array.isArray(raw)) {
      return raw as ProductAttribute[];
    }

    if (raw !== null && typeof raw === 'object') {
      return Object.entries(raw as Record<string, unknown>).map(([key, value]) => ({
        key,
        value: String(value),
      }));
    }

    return [];
  }
}
