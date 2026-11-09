export type ImportJobStatus = 'queued' | 'processing' | 'completed' | 'failed';

/** Ошибка обработки строки XLSX. */
export interface ImportJobRowError {
  type?: 'row';
  row: number;
  externalCode?: string;
  message: string;
}

/** Ошибка скачивания изображения (бэкенд: ImportProductsHandler::downloadImages). */
export interface ImportJobImageError {
  type: 'image';
  productId: number;
  url: string;
  message: string;
}

export type ImportJobError = ImportJobRowError | ImportJobImageError;

export interface ImportJobResult {
  jobId: string;
  status: ImportJobStatus;
  imported?: number;
  updated?: number;
  errors?: ImportJobError[];
  /** Причина падения задачи (status === 'failed'). */
  error?: string | null;
}

export interface ImportUploadResponse {
  jobId: string;
  status: string;
}

/** Строка таблицы «Ошибки импорта» в драуере. */
export interface ImportJobErrorRow {
  kindLabel: string;
  target: string;
  message: string;
}

export function isImageError(error: ImportJobError): error is ImportJobImageError {
  return 'url' in error;
}

export function isRowError(error: ImportJobError): error is ImportJobRowError {
  return !isImageError(error);
}

export function toImportErrorRows(errors: ImportJobError[] | undefined): ImportJobErrorRow[] {
  return (errors ?? []).map((error) =>
    isImageError(error)
      ? {
          kindLabel: 'Изображение',
          target: error.url,
          message: error.message,
        }
      : {
          kindLabel: 'Строка',
          target: error.externalCode
            ? `строка ${error.row} · ${error.externalCode}`
            : `строка ${error.row}`,
          message: error.message,
        },
  );
}

export function firstErrorMessage(errors: ImportJobError[] | undefined): string | null {
  return errors?.[0]?.message ?? null;
}
