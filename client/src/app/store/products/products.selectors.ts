import { createFeatureSelector, createSelector } from '@ngrx/store';
import { ProductsState } from './products.state';

export const selectProductsState = createFeatureSelector<ProductsState>('products');

export const selectAllProducts = createSelector(selectProductsState, (state) => state.products);

export const selectProductsLoading = createSelector(selectProductsState, (state) => state.loading);

export const selectProductsError = createSelector(selectProductsState, (state) => state.error);

export const selectProductsMeta = createSelector(selectProductsState, (state) => state.meta);

export const selectTotalPages = createSelector(selectProductsMeta, (meta) => meta?.totalPages ?? 0);
