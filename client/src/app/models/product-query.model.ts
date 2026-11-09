export interface ProductQuery {
  page?: number;
  limit?: number;
  name?: string;
  price_min?: number;
  price_max?: number;
  sort?: 'id' | 'name';
}
