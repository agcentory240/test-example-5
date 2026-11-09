import { HttpInterceptorFn } from '@angular/common/http';
import { environment } from '../../environments/environment';

/**
 * Заголовок, который сообщает бесплатному ngrok, что запрос идёт из API-клиента,
 * а не из браузера. Без него ngrok на GET-запросы может вернуть HTML-заглушку
 * (ERR_NGROK_6024) вместо JSON.
 *
 * Важно: кастомный заголовок вызывает CORS preflight (OPTIONS), поэтому
 * `ngrok-skip-browser-warning` разрешён в `Access-Control-Allow-Headers`
 * на бэкенде (см. App\Middleware\CorsMiddleware).
 *
 * Заголовок добавляется только к запросам на собственный API, чтобы не влиять
 * на сторонние ресурсы (картинки товаров и т.п.).
 */
const NGROK_SKIP_BROWSER_WARNING = 'ngrok-skip-browser-warning';

export const ngrokInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.startsWith(environment.apiUrl)) {
    return next(req);
  }

  return next(
    req.clone({
      setHeaders: { [NGROK_SKIP_BROWSER_WARNING]: 'true' },
    }),
  );
};
