import { environment } from '../../environments/environment';
import { ProductImage } from '../models/product.model';

/**
 * Кандидаты на отображение изображения в порядке приоритета:
 * локальный файл (path) → исходный URL (url) → инициалы.
 */
export function imageCandidates(image: ProductImage | null | undefined): string[] {
  if (!image) {
    return [];
  }

  const candidates: string[] = [];

  const path = image.path?.trim();

  if (path) {
    candidates.push(`${environment.apiUrl}/${path.replace(/^\/+/, '')}`);
  }

  const url = image.url?.trim();

  if (url) {
    candidates.push(url);
  }

  return candidates;
}

/**
 * Возвращает первый не сломавшийся источник изображения или null,
 * если показывать нечего (тогда рендерим инициалы).
 *
 * @param failed — набор src, которые уже не загрузились
 */
export function pickImageSource(
  image: ProductImage | null | undefined,
  failed: ReadonlySet<string>,
): string | null {
  return imageCandidates(image).find((src) => !failed.has(src)) ?? null;
}

/**
 * Инициалы для p-avatar: первые буквы первых двух слов названия.
 * «Кресло офисное» → «КО», «Стол» → «С».
 */
export function productInitials(name: string | null | undefined): string {
  const words = (name ?? '').trim().split(/\s+/).filter((word) => word !== '');

  if (words.length === 0) {
    return '?';
  }

  const first = Array.from(words[0])[0] ?? '';
  const second = words.length > 1 ? (Array.from(words[1])[0] ?? '') : '';

  return (first + second).toUpperCase();
}
