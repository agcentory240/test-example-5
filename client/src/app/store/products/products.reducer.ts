import { createReducer, on } from '@ngrx/store';
import { ProductsActions } from './products.actions';
import { initialProductsState } from './products.state';

export const productsReducer = createReducer(
  initialProductsState,
  on(ProductsActions.loadProducts, (state, { query }) => ({
    ...state,
    loading: true,
    error: null,
    query: { ...state.query, ...query },
  })),
  on(ProductsActions.loadProductsSuccess, (state, { products, meta }) => ({
    ...state,
    products,
    meta,
    loading: false,
    error: null,
  })),
  on(ProductsActions.loadProductsFailure, (state, { error }) => ({
    ...state,
    loading: false,
    error,
  })),
);
