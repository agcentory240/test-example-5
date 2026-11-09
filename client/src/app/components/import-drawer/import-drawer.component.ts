import { AsyncPipe } from '@angular/common';
import { Component, DestroyRef, EventEmitter, Input, Output, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MessageService } from 'primeng/api';
import { DrawerModule } from 'primeng/drawer';
import { FileSelectEvent, FileUploadModule } from 'primeng/fileupload';
import { ButtonModule } from 'primeng/button';
import { ProgressBarModule } from 'primeng/progressbar';
import { ImportJobResult, ImportJobStatus } from '../../models/import-job-status.model';
import { ImportService } from '../../services/import.service';

const STATUS_LABEL: Record<ImportJobStatus, string> = {
  queued: 'В очереди',
  processing: 'Обработка',
  completed: 'Завершён',
  failed: 'Ошибка',
};

@Component({
  selector: 'app-import-drawer',
  standalone: true,
  imports: [
    AsyncPipe,
    DrawerModule,
    FileUploadModule,
    ButtonModule,
    ProgressBarModule,
  ],
  template: `
    <p-drawer
      [visible]="visible"
      (visibleChange)="visibleChange.emit($event)"
      position="right"
      header="Импорт товаров"
      [style]="{ width: '450px' }"
    >
      <div class="drawer-body">
        <p-fileupload
          mode="basic"
          accept=".xlsx"
          chooseLabel="Выбрать файл"
          [auto]="false"
          [customUpload]="true"
          (onSelect)="onFileSelect($event)"
        />

        @if (selectedFileName) {
          <p class="drawer-file">Выбран: {{ selectedFileName }}</p>
        }

        <button
          pButton
          type="button"
          label="Загрузить"
          [disabled]="!selectedFile || (active$ | async)"
          (click)="onUpload()"
        ></button>

        @if (active$ | async) {
          <p-progressbar mode="indeterminate" [style]="{ height: '6px' }" />
        }

        <section class="drawer-history">
          <h3>История импортов</h3>
          @if (history$ | async; as history) {
            @if (history.length) {
              <ul class="history-list">
                @for (job of history; track job.jobId) {
                  <li class="history-item" [class]="'history-item--' + job.status">
                    <span class="history-item__status">{{ statusLabel(job.status) }}</span>
                    <span class="history-item__meta">{{ metaLabel(job) }}</span>
                    <span class="history-item__id">{{ job.jobId }}</span>
                  </li>
                }
              </ul>
            } @else {
              <p>Нет импортов</p>
            }
          }
        </section>

        <section class="drawer-logs">
          <h3>Логи поллинга</h3>
          <div class="logs-panel">
            @if (logs$ | async; as logs) {
              @for (log of logs; track $index) {
                <p class="log-line">{{ log }}</p>
              } @empty {
                <p class="log-line">Пока пусто</p>
              }
            }
          </div>
        </section>
      </div>
    </p-drawer>
  `,
  styles: [
    `
      .drawer-body {
        display: flex;
        flex-direction: column;
        gap: 1rem;
      }

      .drawer-file {
        margin: 0;
        font-size: 0.875rem;
        color: #4b5563;
        word-break: break-all;
      }

      .drawer-history h3,
      .drawer-logs h3 {
        margin: 0 0 0.5rem;
        font-size: 1rem;
      }

      .drawer-history > p {
        margin: 0;
        color: var(--p-text-muted-color, #6b7280);
      }

      .history-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .history-item {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem 0.5rem;
        padding: 0.5rem;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        font-size: 0.8125rem;
      }

      .history-item__status {
        font-weight: 600;
      }

      .history-item--queued .history-item__status {
        color: #6b7280;
      }

      .history-item--processing .history-item__status {
        color: #2563eb;
      }

      .history-item--completed .history-item__status {
        color: #16a34a;
      }

      .history-item--failed .history-item__status {
        color: #dc2626;
      }

      .history-item__meta {
        color: #4b5563;
      }

      .history-item__id {
        width: 100%;
        font-size: 0.6875rem;
        color: #9ca3af;
        word-break: break-all;
      }

      .logs-panel {
        max-height: 200px;
        overflow-y: auto;
        padding: 0.5rem;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #fafafa;
      }

      .log-line {
        margin: 0 0 0.125rem;
        font-size: 0.75rem;
        color: #6b7280;
      }
    `,
  ],
})
export class ImportDrawerComponent {
  @Input() visible = false;
  @Output() visibleChange = new EventEmitter<boolean>();

  private readonly importService = inject(ImportService);
  private readonly messageService = inject(MessageService);
  private readonly destroyRef = inject(DestroyRef);

  readonly history$ = this.importService.history$;
  readonly logs$ = this.importService.logs$;
  readonly active$ = this.importService.active$;

  selectedFile: File | null = null;
  selectedFileName = '';

  onFileSelect(event: FileSelectEvent): void {
    const file = event.files?.[0] ?? null;
    this.selectedFile = file;
    this.selectedFileName = file?.name ?? '';
  }

  statusLabel(status: ImportJobStatus): string {
    return STATUS_LABEL[status];
  }

  metaLabel(job: ImportJobResult): string {
    const base = `создано ${job.imported ?? 0}, обновлено ${job.updated ?? 0}`;
    const errors = job.errors?.length ?? 0;

    return errors > 0 ? `${base}, ошибок ${errors}` : base;
  }

  onUpload(): void {
    const file = this.selectedFile;

    if (!file) {
      return;
    }

    this.selectedFile = null;
    this.selectedFileName = '';

    this.importService
      .startImport(file)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (result) => this.notify(result),
        error: (error: unknown) =>
          this.toast('error', 'Ошибка загрузки', this.errorMessage(error)),
      });
  }

  private notify(result: ImportJobResult): void {
    const errors = result.errors?.length ?? 0;

    switch (result.status) {
      case 'queued':
        this.toast('success', 'Импорт', 'Импорт поставлен в очередь');
        break;

      case 'completed':
        if (errors > 0) {
          this.toast(
            'warn',
            'Импорт',
            `Импорт завершён, но не все файлы загружены: ${errors} ошибок`,
          );
        } else {
          this.toast(
            'success',
            'Импорт',
            `Импорт успешно завершён: создано ${result.imported ?? 0}, обновлено ${result.updated ?? 0}`,
          );
        }
        break;

      case 'failed':
        this.toast('error', 'Импорт', 'Импорт завершён с ошибкой');
        break;
    }
  }

  private toast(severity: 'success' | 'info' | 'warn' | 'error', summary: string, detail: string): void {
    this.messageService.add({ severity, summary, detail });
  }

  private errorMessage(error: unknown): string {
    if (error && typeof error === 'object' && 'error' in error) {
      const body = (error as { error?: { message?: string } }).error;

      if (body?.message) {
        return body.message;
      }
    }

    return error instanceof Error ? error.message : 'неизвестная ошибка';
  }
}
