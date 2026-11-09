import { createActionGroup, props } from '@ngrx/store';
import { Product } from '../../models/product.model';
import { ProductQuery } from '../../models/product-query.model';
import { PaginatedMeta } from '../../models/paginated-response.model';

export const ProductsActions = createActionGroup({
  source: 'Products',
  events: {
    'Load Products': props<{ query: ProductQuery }>(),
    'Load Products Success': props<{ products: Product[]; meta: PaginatedMeta }>(),
    'Load Products Failure': props<{ error: string }>(),
  },
});
