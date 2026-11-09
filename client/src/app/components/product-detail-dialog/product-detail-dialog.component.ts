import { Component, EventEmitter, Input, Output } from '@angular/core';
import { DialogModule } from 'primeng/dialog';
import { Product } from '../../models/product.model';

@Component({
  selector: 'app-product-detail-dialog',
  standalone: true,
  imports: [DialogModule],
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
    `,
  ],
})
export class ProductDetailDialogComponent {
  @Input() visible = false;
  @Input() product: Product | null = null;
  @Output() visibleChange = new EventEmitter<boolean>();
}
