export interface ProductAttribute {
  key: string;
  value: string;
}

export interface ProductImage {
  url: string;
  path: string | null;
}

export interface Product {
  id: number;
  externalCode: string;
  uuid: string | null;
  name: string;
  description: string | null;
  price: number;
  purchasePrice: number | null;
  discount: number | null;
  createdAt: string;
  attributes: ProductAttribute[];
  images: ProductImage[];
}
