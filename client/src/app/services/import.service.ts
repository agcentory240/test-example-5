import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ImportJobResult, ImportUploadResponse } from '../models/import-job-status.model';

@Injectable({ providedIn: 'root' })
export class ImportService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = `${environment.apiUrl}/api/import`;

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
}
