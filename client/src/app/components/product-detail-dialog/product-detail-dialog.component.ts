import { DecimalPipe } from '@angular/common';
import { Component, EventEmitter, Input, Output, computed, signal } from '@angular/core';
import { AvatarModule } from 'primeng/avatar';
import { DialogModule } from 'primeng/dialog';
import { GalleriaModule } from 'primeng/galleria';
import { Product } from '../../models/product.model';
import { imageCandidates, productInitials } from '../../utils/product-image.util';

interface GalleryImage {
  src: string;
  alt: string;
}

@Component({
  selector: 'app-product-detail-dialog',
  standalone: true,
  imports: [DialogModule, GalleriaModule, AvatarModule, DecimalPipe],
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

      .detail__empty-image {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        color: var(--p-text-muted-color, #6b7280);
      }
    `,
  ],
})
export class ProductDetailDialogComponent {
  @Input() visible = false;
  @Output() visibleChange = new EventEmitter<boolean>();

  @Input()
  set product(value: Product | null) {
    this.productSource.set(value);
  }

  get product(): Product | null {
    return this.productSource();
  }

  private readonly productSource = signal<Product | null>(null);

  /**
   * src, которые не загрузились (битый локальный файл или мёртвый исходный URL).
   */
  private readonly failedImageSources = signal<ReadonlySet<string>>(new Set());

  /**
   * Только загружаемые источники: path → url.
   * Если ни один не работает — массив пуст, показываем инициалы.
   */
  readonly galleryImages = computed<GalleryImage[]>(() => {
    const product = this.productSource();

    if (!product) {
      return [];
    }

    const failed = this.failedImageSources();
    const result: GalleryImage[] = [];

    for (const image of product.images ?? []) {
      const src = imageCandidates(image).find((candidate) => !failed.has(candidate));

      if (src) {
        result.push({ src, alt: product.name });
      }
    }

    return result;
  });

  readonly productInitial = computed(() => productInitials(this.productSource()?.name));

  onImageError(src: string): void {
    this.failedImageSources.update((failed) => new Set(failed).add(src));
  }
}
