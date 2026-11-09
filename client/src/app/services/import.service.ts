import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
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
} from '../models/import-job-status.model';

const POLL_INTERVAL_MS = 2000;
const MAX_LOGS = 100;

const STATUS_LOG: Record<ImportJobStatus, string> = {
  queued: 'Ожидание в очереди...',
  processing: 'Обработка...',
  completed: 'Завершено',
  failed: 'Импорт завершён с ошибкой',
};

@Injectable({ providedIn: 'root' })
export class ImportService {
  private readonly http = inject(HttpClient);
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
          }),
          takeWhile(
            (result) => result.status !== 'completed' && result.status !== 'failed',
            true,
          ),
          catchError((error: unknown) => {
            this.applyEntry({ jobId, status: 'failed' });
            this.log(jobId, 'failed');
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
      this.historySubject.next([entry, ...current]);
      return;
    }

    const next = current.slice();
    next[index] = { ...current[index], ...entry };
    this.historySubject.next(next);
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
