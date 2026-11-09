import { Product } from '../../models/product.model';
import { PaginatedMeta } from '../../models/paginated-response.model';
import { ProductQuery } from '../../models/product-query.model';

export interface ProductsState {
  products: Product[];
  meta: PaginatedMeta | null;
  loading: boolean;
  error: string | null;
  query: ProductQuery;
}

export const initialProductsState: ProductsState = {
  products: [],
  meta: null,
  loading: false,
  error: null,
  query: { page: 1, limit: 10, sort: 'id', order: 'asc' },
};
