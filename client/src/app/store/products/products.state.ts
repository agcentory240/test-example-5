import { Product } from '../../models/product.model';
import { PaginatedMeta } from '../../models/paginated-response.model';

export interface ProductsState {
  products: Product[];
  meta: PaginatedMeta | null;
  loading: boolean;
  error: string | null;
}

export const initialProductsState: ProductsState = {
  products: [],
  meta: null,
  loading: false,
  error: null,
};
