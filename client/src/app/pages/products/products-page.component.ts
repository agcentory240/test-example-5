import { Component } from '@angular/core';
import { TableModule } from 'primeng/table';
import { Product } from '../../models/product.model';
import { ProductDetailDialogComponent } from '../../components/product-detail-dialog/product-detail-dialog.component';

@Component({
  selector: 'app-products-page',
  standalone: true,
  imports: [TableModule, ProductDetailDialogComponent],
  template: `
    <div class="products-page">
      <p-table
        [value]="products"
        [paginator]="true"
        [rows]="10"
        dataKey="id"
        selectionMode="single"
        [(selection)]="selectedProduct"
        (onRowSelect)="openDetail()"
        [tableStyle]="{ 'min-width': '50rem' }"
      >
        <ng-template pTemplate="header">
          <tr>
            <th>ID</th>
            <th>Внешний код</th>
            <th>Наименование</th>
            <th>Цена</th>
            <th>Скидка</th>
            <th>Дата создания</th>
          </tr>
        </ng-template>

        <ng-template pTemplate="body" let-product>
          <tr [pSelectableRow]="product">
            <td>{{ product.id }}</td>
            <td>{{ product.externalCode }}</td>
            <td>{{ product.name }}</td>
            <td>{{ product.price }}</td>
            <td>{{ product.discount ?? '—' }}</td>
            <td>{{ product.createdAt }}</td>
          </tr>
        </ng-template>

        <ng-template pTemplate="emptymessage">
          <tr>
            <td colspan="6">Нет товаров</td>
          </tr>
        </ng-template>
      </p-table>
    </div>

    <app-product-detail-dialog [(visible)]="dialogVisible" [product]="dialogProduct" />
  `,
  styles: [
    `
      .products-page {
        padding: 1.5rem;
      }
    `,
  ],
})
export class ProductsPageComponent {
  // Временные данные для проверки клика по строке. Убрать при подключении NgRx.
  products: Product[] = [
    {
      id: 1,
      externalCode: 'SKU-001',
      uuid: null,
      name: 'Товар 1',
      description: null,
      price: 1000,
      purchasePrice: null,
      discount: 10,
      createdAt: '2026-01-15',
      attributes: [],
      images: [],
    },
    {
      id: 2,
      externalCode: 'SKU-002',
      uuid: null,
      name: 'Товар 2',
      description: null,
      price: 2500,
      purchasePrice: null,
      discount: null,
      createdAt: '2026-02-20',
      attributes: [],
      images: [],
    },
    {
      id: 3,
      externalCode: 'SKU-003',
      uuid: null,
      name: 'Товар 3',
      description: null,
      price: 700,
      purchasePrice: null,
      discount: 5,
      createdAt: '2026-03-05',
      attributes: [],
      images: [],
    },
  ];

  selectedProduct: Product | null = null;
  dialogProduct: Product | null = null;
  dialogVisible = false;

  openDetail(): void {
    this.dialogProduct = this.selectedProduct;
    this.dialogVisible = true;
  }
}
