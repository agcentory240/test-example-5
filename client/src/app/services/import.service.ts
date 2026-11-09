import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Store } from '@ngrx/store';
import {
  BehaviorSubject,
  Observable,
  catchError,
  concat,
  interval,
  map,
  of,
  switchMap,
  takeWhile,
  tap,
  throwError,
} from 'rxjs';
import { environment } from '../../environments/environment';
import {
  ImportJobResult,
  ImportJobStatus,
  ImportUploadResponse,
  isImageError,
  isRowError,
} from '../models/import-job-status.model';
import { ProductsActions } from '../store/products/products.actions';

const POLL_INTERVAL_MS = 2000;
const MAX_LOGS = 100;
const MAX_ERROR_LINES = 10;
const MAX_TRACKED_JOBS = 20;

const STATUS_LOG: Record<ImportJobStatus, string> = {
  queued: 'Ожидание в очереди...',
  processing: 'Обработка...',
  completed: 'Завершено',
  failed: 'Импорт завершён с ошибкой',
};

@Injectable({ providedIn: 'root' })
export class ImportService {
  private readonly http = inject(HttpClient);
  private readonly store = inject(Store);
  private readonly baseUrl = `${environment.apiUrl}/api/import`;

  private readonly historySubject = new BehaviorSubject<ImportJobResult[]>([]);
  private readonly logsSubject = new BehaviorSubject<string[]>([]);

  /** История импортов — живёт в сервисе, переживает закрытие драуера. */
  readonly history$ = this.historySubject.asObservable();

  /** Логи поллинга — общие, дополняются при изменении статуса. */
  readonly logs$ = this.logsSubject.asObservable();

  /** Есть ли активный импорт (queued/processing). */
  readonly active$ = this.history$.pipe(
    map((jobs) => jobs.some((job) => job.status === 'queued' || job.status === 'processing')),
  );

  /** Последний статус, попавший в лог, — чтобы не дублировать каждые 2 секунды. */
  private readonly lastLoggedStatus = new Map<string, ImportJobStatus>();
  private readonly processingTicks = new Map<string, number>();

  /** Задачи, чьи ошибки уже выведены в лог (поллинг не должен дублировать строки). */
  private readonly loggedErrors = new Set<string>();

  /** Задачи, о завершении которых уже отправлен action (защита от дублей поллинга). */
  private readonly finishedJobs = new Set<string>();

  upload(file: File): Observable<ImportUploadResponse> {
    const formData = new FormData();
    formData.append('file', file);

    return this.http.post<ImportUploadResponse>(this.baseUrl, formData);
  }

  getStatus(jobId: string): Observable<ImportJobResult> {
    return this.http.get<ImportJobResult>(
      `${this.baseUrl}/${encodeURIComponent(jobId)}/status`,
    );
  }

  startImport(file: File): Observable<ImportJobResult> {
    return this.upload(file).pipe(
      switchMap(({ jobId }) => {
        const queued: ImportJobResult = { jobId, status: 'queued' };
        this.applyEntry(queued);
        this.log(jobId, 'queued');

        const polling$ = interval(POLL_INTERVAL_MS).pipe(
          switchMap(() => this.getStatus(jobId)),
          tap((result) => {
            this.applyEntry(result);
            this.log(jobId, result.status);
            this.logDownloading(jobId, result.status);
            this.logErrors(jobId, result);
            this.notifyImportFinished(jobId, result);
          }),
          takeWhile(
            (result) => result.status !== 'completed' && result.status !== 'failed',
            true,
          ),
          catchError((error: unknown) => {
            this.applyEntry({ jobId, status: 'failed' });
            this.log(jobId, 'failed');
            this.notifyImportFinished(jobId, { jobId, status: 'failed' });

            return throwError(() => error);
          }),
        );

        return concat(of(queued), polling$);
      }),
    );
  }

  private applyEntry(entry: ImportJobResult): void {
    const current = this.historySubject.value;
    const index = current.findIndex((item) => item.jobId === entry.jobId);

    if (index === -1) {
      const next = [entry, ...current];
      this.historySubject.next(next);
      this.pruneTracking(next);
      return;
    }

    const next = current.slice();
    next[index] = { ...current[index], ...entry };
    this.historySubject.next(next);
  }

  /**
   * Поллинг не должен дублировать строки лога: ошибки задачи выводим один раз —
   * при переходе в completed/failed.
   */
  private logErrors(jobId: string, result: ImportJobResult): void {
    if (result.status !== 'completed' && result.status !== 'failed') {
      return;
    }

    if (this.loggedErrors.has(jobId)) {
      return;
    }

    this.loggedErrors.add(jobId);

    if (result.status === 'failed') {
      this.pushLog(`${this.time()} Задача упала: ${result.error ?? 'причина не указана'}`);
    }

    const errors = result.errors ?? [];

    if (errors.length === 0) {
      return;
    }

    const imageErrors = errors.filter(isImageError);
    const rowErrors = errors.filter(isRowError);

    if (imageErrors.length > 0) {
      this.pushLog(
        `${this.time()} Скачивание изображений: ${imageErrors.length} ${this.plural(imageErrors.length)}`,
      );

      for (const error of imageErrors.slice(0, MAX_ERROR_LINES)) {
        this.pushLog(`${this.time()}   ${error.url} — ${error.message}`);
      }

      if (imageErrors.length > MAX_ERROR_LINES) {
        this.pushLog(
          `${this.time()}   ... и ещё ${imageErrors.length - MAX_ERROR_LINES} ошибок`,
        );
      }
    }

    if (rowErrors.length > 0) {
      this.pushLog(`${this.time()} Ошибки строк: ${rowErrors.length}`);

      for (const error of rowErrors.slice(0, MAX_ERROR_LINES)) {
        this.pushLog(`${this.time()}   строка ${error.row} — ${error.message}`);
      }

      if (rowErrors.length > MAX_ERROR_LINES) {
        this.pushLog(`${this.time()}   ... и ещё ${rowErrors.length - MAX_ERROR_LINES} ошибок`);
      }
    }
  }

  /**
   * NgRx узнаёт о завершении импорта через action: эффект `importCompleted$`
   * перечитывает список товаров с текущими фильтрами.
   */
  private notifyImportFinished(jobId: string, result: ImportJobResult): void {
    if (result.status !== 'completed' && result.status !== 'failed') {
      return;
    }

    if (this.finishedJobs.has(jobId)) {
      return;
    }

    this.finishedJobs.add(jobId);

    this.store.dispatch(
      ProductsActions.importCompleted({
        jobId,
        errors: result.errors?.length ?? 0,
      }),
    );
  }

  /** Ограничивает служебные Map/Set последними задачами, чтобы не росли бесконечно. */
  private pruneTracking(history: ImportJobResult[]): void {
    const keep = new Set(history.slice(0, MAX_TRACKED_JOBS).map((job) => job.jobId));

    for (const jobId of [...this.loggedErrors]) {
      if (!keep.has(jobId)) {
        this.loggedErrors.delete(jobId);
      }
    }

    for (const jobId of [...this.finishedJobs]) {
      if (!keep.has(jobId)) {
        this.finishedJobs.delete(jobId);
      }
    }

    for (const jobId of [...this.lastLoggedStatus.keys()]) {
      if (!keep.has(jobId)) {
        this.lastLoggedStatus.delete(jobId);
      }
    }

    for (const jobId of [...this.processingTicks.keys()]) {
      if (!keep.has(jobId)) {
        this.processingTicks.delete(jobId);
      }
    }
  }

  private plural(count: number): string {
    return count === 1 ? 'ошибка' : 'ошибок';
  }

  private log(jobId: string, status: ImportJobStatus): void {
    if (this.lastLoggedStatus.get(jobId) === status) {
      return;
    }

    this.lastLoggedStatus.set(jobId, status);
    this.pushLog(`${this.time()} ${STATUS_LOG[status]}`);
  }

  private logDownloading(jobId: string, status: ImportJobStatus): void {
    if (status !== 'processing') {
      this.processingTicks.delete(jobId);
      return;
    }

    const ticks = (this.processingTicks.get(jobId) ?? 0) + 1;
    this.processingTicks.set(jobId, ticks);

    if (ticks === 2) {
      this.pushLog(`${this.time()} Скачивание изображений...`);
    }
  }

  private pushLog(message: string): void {
    const logs = [...this.logsSubject.value, message];
    this.logsSubject.next(logs.slice(-MAX_LOGS));
  }

  private time(): string {
    return new Date().toLocaleTimeString('ru-RU');
  }
}
