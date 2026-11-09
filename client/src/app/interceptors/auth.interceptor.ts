import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';

const PUBLIC_PATHS = ['/api/login', '/api/health'];

/**
 * Добавляет Authorization: Bearer <token> к защищённым запросам.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const token = inject(AuthService).getToken();
  const isPublic = PUBLIC_PATHS.some((path) => req.url.includes(path));

  if (token === null || isPublic) {
    return next(req);
  }

  return next(req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }));
};
