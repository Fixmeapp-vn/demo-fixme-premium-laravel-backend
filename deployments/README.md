# Deployments

Reference runtime configuration for the HTTP and worker processes. Container
images, Compose/Sail and production infrastructure are out of scope until
BE-012; these files document how the platform settings map onto each layer.

| File | Purpose |
| --- | --- |
| `nginx/default.conf.template` | Public API listener + internal metrics listener; header/idle/upstream timeouts. Render with `envsubst`. |
| `php-fpm/www.conf` | FPM pool: `request_terminate_timeout` from `HTTP_REQUEST_TIMEOUT` (FPM expands `${VAR}`). |
| `php-fpm/php-fpm.conf` | `process_control_timeout` from `SHUTDOWN_TIMEOUT`. |
| `supervisor/worker.conf` | Queue worker; `stopwaitsecs` from `SHUTDOWN_TIMEOUT` (`%(ENV_…)s`). |

## Timeout mapping

| Setting | PHP | nginx | FPM | Supervisor |
| --- | --- | --- | --- | --- |
| `HTTP_REQUEST_TIMEOUT` | `set_time_limit()` per request (`EnforceRequestTimeout`), **CPU time only** on Linux | `fastcgi_read_timeout` = value + 5s | `request_terminate_timeout` = value (**wall clock**) | – |
| `HTTP_READ_HEADER_TIMEOUT` | – | `client_header_timeout` | – | – |
| `HTTP_IDLE_TIMEOUT` | – | `keepalive_timeout` | – | – |
| `QUEUE_JOB_TIMEOUT` | `queue:work --timeout` (`platform:worker`): max runtime of one job | – | – | – |
| `SHUTDOWN_TIMEOUT` | validated `> QUEUE_JOB_TIMEOUT` | `worker_shutdown_timeout` | `process_control_timeout` | `stopwaitsecs` |

`set_time_limit()` counts CPU time on Linux, so a request blocked on I/O (database, HTTP
calls, `sleep`) is only stopped by FPM's `request_terminate_timeout`, which is why that one
is set to `HTTP_REQUEST_TIMEOUT` itself. A request killed by FPM is answered by nginx with a
502 rather than a Problem Details 500.

## Metrics listener

`/metrics` is served only when `SERVER_PORT` equals the `METRICS_ADDR` port. Our nginx
template passes `fastcgi_param SERVER_PORT $server_port`; **any other proxy or server in
front of PHP must forward the real listener port the same way**, otherwise `/metrics`
answers 404 everywhere (it fails closed).

## Graceful shutdown

- **nginx**: `SIGQUIT` (`nginx -s quit`) stops accepting connections and waits for in-flight
  requests, bounded by `worker_shutdown_timeout`.
- **PHP-FPM**: `SIGQUIT` performs a graceful stop; `SIGUSR2` a graceful reload. Children get
  `process_control_timeout` to finish the current request before being killed.
- **Queue worker**: `SIGTERM`/`SIGINT` let the current job finish, then the process exits 0.
  A job exceeding `QUEUE_JOB_TIMEOUT` is killed and the worker exits non-zero; supervisor's
  `stopwaitsecs` (`SHUTDOWN_TIMEOUT`) is always longer, so a graceful stop is never cut short.
