export type ProductSortField = 'id' | 'name' | 'price';

export type ProductSortOrder = 'asc' | 'desc';

export interface ProductQuery {
  page?: number;
  limit?: number;
  name?: string;
  price_min?: number;
  price_max?: number;
  sort?: ProductSortField;
  order?: ProductSortOrder;
}
