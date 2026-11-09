import { DecimalPipe } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { DialogModule } from 'primeng/dialog';
import { environment } from '../../../environments/environment';
import { Product, ProductImage } from '../../models/product.model';

@Component({
  selector: 'app-product-detail-dialog',
  standalone: true,
  imports: [DialogModule, DecimalPipe],
  templateUrl: './product-detail-dialog.component.html',
  styles: [
    `
      .detail {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .detail__row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
      }

      .detail__label {
        color: var(--p-text-muted-color, #6b7280);
      }

      .detail__section h4 {
        margin: 0 0 0.5rem;
        font-size: 1rem;
      }

      .detail__section p {
        margin: 0;
        color: var(--p-text-muted-color, #6b7280);
      }

      .detail__images {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 0.5rem;
      }

      .detail__image {
        width: 100%;
        height: 120px;
        object-fit: cover;
        border-radius: 0.5rem;
        border: 1px solid var(--p-content-border-color, #e5e7eb);
      }
    `,
  ],
})
export class ProductDetailDialogComponent {
  @Input() visible = false;
  @Input() product: Product | null = null;
  @Output() visibleChange = new EventEmitter<boolean>();

  imageUrl(image: ProductImage): string {
    if (image.path) {
      return `${environment.apiUrl}/${image.path.replace(/^\/+/, '')}`;
    }

    return image.url;
  }
}
