export type ImportJobStatus = 'queued' | 'processing' | 'completed' | 'failed';

export interface ImportJobError {
  row: number;
  externalCode?: string;
  message: string;
}

export interface ImportJobResult {
  jobId: string;
  status: ImportJobStatus;
  imported?: number;
  updated?: number;
  errors?: ImportJobError[];
}

export interface ImportUploadResponse {
  jobId: string;
  status: string;
}
