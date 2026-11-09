import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

/**
 * Логирует сетевые ошибки и 401/500, но не глотает их —
 * ошибка уходит дальше, чтобы компонент показал сообщение.
 */
export const httpErrorInterceptor: HttpInterceptorFn = (req, next) =>
  next(req).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse) {
        if (error.status === 0) {
          console.error('[HTTP] network error', req.method, req.url, error.message);
        } else if (error.status === 401) {
          console.warn('[HTTP 401] unauthorized', req.method, req.url);
        } else if (error.status === 500) {
          console.error('[HTTP 500]', req.method, req.url, error.error);
        }
      }

      return throwError(() => error);
    }),
  );
