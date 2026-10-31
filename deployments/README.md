# Deployments

Reference runtime configuration for the HTTP and worker processes. Container
images, Compose/Sail and production infrastructure are out of scope until
BE-012; these files document how the platform settings map onto each layer.

| File | Purpose |
| --- | --- |
| `nginx/default.conf.template` | Public API listener + internal metrics listener; header/idle/upstream timeouts. Render with `envsubst`. |
| `php-fpm/www.conf` | FPM pool: `request_terminate_timeout`, graceful reload. |
| `php-fpm/php-fpm.conf` | `process_control_timeout` (graceful shutdown deadline). |
| `supervisor/worker.conf` | Queue worker process with a stop grace period above `SHUTDOWN_TIMEOUT`. |

## Timeout mapping

| Setting | PHP | nginx | FPM | Supervisor |
| --- | --- | --- | --- | --- |
| `HTTP_REQUEST_TIMEOUT` | `set_time_limit()` per request (`EnforceRequestTimeout`) | `fastcgi_read_timeout` = value + 5s | `request_terminate_timeout` = value + 10s | – |
| `HTTP_READ_HEADER_TIMEOUT` | – | `client_header_timeout` | – | – |
| `HTTP_IDLE_TIMEOUT` | – | `keepalive_timeout` | – | – |
| `SHUTDOWN_TIMEOUT` | `queue:work --timeout` (`platform:worker`) | `worker_shutdown_timeout` | `process_control_timeout` | `stopwaitsecs` = value + 10s |

## Graceful shutdown

- **nginx**: `SIGQUIT` (`nginx -s quit`) stops accepting connections and waits for in-flight
  requests, bounded by `worker_shutdown_timeout`.
- **PHP-FPM**: `SIGQUIT` performs a graceful stop; `SIGUSR2` a graceful reload. Children get
  `process_control_timeout` to finish the current request before being killed.
- **Queue worker**: `SIGTERM`/`SIGINT` let the current job finish, then the process exits 0.
  A job exceeding `--timeout` is killed and the worker exits non-zero.
