# FixMe Premium – Laravel backend

Modular-monolith backend for FixMe Premium: one Laravel application with two
entry points, the **HTTP API** and the **queue worker**. This repository
currently contains the application foundation (BE-001); business modules arrive
in later stories.

## Prerequisites

| Requirement | Version / notes |
| --- | --- |
| PHP | **8.3+** (`composer.json` pins `php: ^8.3`, platform `8.3.0`) |
| PHP extensions | `mbstring`, `json`, `ctype`, `tokenizer`, `xml`, `openssl`, `pdo` (Laravel defaults) and **`pcntl`** (graceful worker shutdown). Optional: `redis` (metrics/cache store), `opcache`. |
| Composer | 2.x |
| Laravel | **13.x** (`laravel/framework: ^13.0`) |

No database, Redis or Docker is needed for this foundation: the queue defaults
to `sync`, and cache/metrics to the `file` store.

## Getting started

```bash
make setup          # composer install, copy .env.example to .env, generate APP_KEY
make run-api        # API on HTTP_ADDR (default http://127.0.0.1:8000)
make run-metrics    # internal metrics listener on METRICS_ADDR (default http://127.0.0.1:9100/metrics)
make run-worker     # queue worker
```

Quick check:

```bash
curl -i http://127.0.0.1:8000/health/live        # 200 {"status":"ok"}
curl -i http://127.0.0.1:8000/nope               # 404 application/problem+json
curl -i -X POST http://127.0.0.1:8000/health/live # 405 + Allow: GET, HEAD
curl -s http://127.0.0.1:9100/metrics            # Prometheus text format
```

## Development commands

| Command | Purpose |
| --- | --- |
| `make run-api` | Run the API locally (`php artisan platform:serve` → `artisan serve` on `HTTP_ADDR`) |
| `make run-metrics` | Run the internal metrics listener (`php artisan platform:serve --metrics`) |
| `make run-worker` | Run the queue worker (`php artisan platform:worker` → `queue:work --timeout=SHUTDOWN_TIMEOUT`) |
| `make build` | Install production dependencies and cache config/routes (`composer install --no-dev`, `artisan optimize`) |
| `make test` | Run the tests (`php artisan test`, Pest) |
| `make test-parallel` | Run the tests in parallel (`php artisan test --parallel`) |
| `make fmt` / `make fmt-check` | Format / check formatting (Laravel Pint) |
| `make lint` | Static analysis (Larastan, level 8) |
| `make check` | `fmt-check` + `lint` + `test` |

Composer equivalents: `composer fmt`, `composer fmt:check`, `composer lint`, `composer test`, `composer test:parallel`.

## Configuration

Configuration comes from environment variables (`.env`) and is read **only**
inside `config/*.php`; application code uses `config()`, so
`php artisan config:cache` is safe. All variables, with defaults and comments,
are listed in [`.env.example`](.env.example).

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_ENV` | `production` | `development`, `test`, `staging` or `production` |
| `APP_KEY` | – | Encryption key; required in `staging`/`production` |
| `SERVICE_NAME` | `fixme-premium-backend` | Service name on every log record |
| `HTTP_ADDR` | `127.0.0.1:8000` | API listen address (`host:port`) |
| `HTTP_REQUEST_TIMEOUT` | `30` | Max PHP execution time per request (1–300 s) |
| `HTTP_READ_HEADER_TIMEOUT` | `10` | Web-server header read timeout (1–120 s) |
| `HTTP_IDLE_TIMEOUT` | `60` | Web-server keep-alive idle timeout (1–900 s) |
| `SHUTDOWN_TIMEOUT` | `30` | Graceful shutdown deadline (1–300 s) |
| `METRICS_ENABLED` | `true` | Serve `/metrics` and record HTTP metrics |
| `METRICS_ADDR` | `127.0.0.1:9100` | Internal metrics listener; must use a different port than `HTTP_ADDR` |
| `METRICS_STORE` | `file` | Cache store holding metric samples (`redis` recommended outside development) |
| `LOG_LEVEL` | `info` | `debug` … `emergency` |

**Environments.** Laravel itself uses `local` and `testing`; `config/app.php`
maps `development → local` and `test → testing`, so `app()->environment()`
keeps working with Laravel conventions while `APP_ENV` only accepts the four
canonical names.

**Validation.** `App\Platform\Config\PlatformConfigValidator` runs on every
boot (HTTP requests, artisan commands, `queue:work`). Invalid configuration
stops the process with a non-zero exit code and lists *every* problem at once,
referencing variable names only (values, including secrets, are never printed
or logged):

```
Invalid application configuration. Fix the following and restart:
  - APP_ENV must be one of: development, test, staging, production.
  - HTTP_REQUEST_TIMEOUT must be an integer number of seconds between 1 and 300.
```

## What is implemented (BE-001)

### HTTP server
- Laravel HTTP kernel. Locally `artisan serve` (via `platform:serve`); in deployed environments PHP-FPM behind nginx — reference configs in [`deployments/`](deployments/README.md).
- Timeouts: `HTTP_REQUEST_TIMEOUT` is enforced in PHP (`EnforceRequestTimeout` → `set_time_limit`); header-read and idle timeouts are web-server settings (`client_header_timeout`, `keepalive_timeout`), validated here and mapped in `deployments/`.
- Routing: `routes/api.php` is mounted under **`/api/v1`**. Modules register their own routes from their ServiceProvider with `Route::prefix('api/v1')->middleware('api')`.
- Bootstrapping lives in `bootstrap/app.php` / `bootstrap/providers.php`; no business logic there.

### Error responses (RFC 9457)
Every error is `application/problem+json` with `type`, `title`, `status`,
`detail` and a stable `code`:

```json
{"type":"about:blank","title":"Not Found","status":404,"detail":"The requested resource was not found.","code":"http.not_found"}
```

| Situation | Status | `code` |
| --- | --- | --- |
| Unknown route | 404 | `http.not_found` |
| Unsupported method (with `Allow` header) | 405 | `http.method_not_allowed` |
| Validation failure (`errors` member added) | 422 | `validation.failed` |
| Unhandled exception | 500 | `internal.error` |
| Module exception implementing `ProvidesProblemDetails` | its own | its own (e.g. `user.email_taken`) |

Exception messages and stack traces are logged, never returned — even with `APP_DEBUG=true`.

### Middleware (global, in order)
1. `AssignRequestId` — accepts a valid `X-Request-ID` (1–128 chars of `A–Z a–z 0–9 . _ : -`), otherwise generates a UUIDv7; stored as a request attribute and in Laravel `Context` (so it is on every log line and propagates to queued jobs); echoed in the response.
2. `RecordHttpMetrics` — request count and duration (terminable).
3. `LogAccess` — one `http.request` JSON log per request with method, **route template**, status, duration and request ID (terminable). Health/metrics probes are logged at `debug`.
4. Laravel defaults (TrustProxies, CORS, maintenance mode, post size, …).
5. `EnforceRequestTimeout`.

### Logging
- Default channel `json` (`config/logging.php`): Monolog `StreamHandler` on stderr with `PlatformJsonFormatter` — one JSON object per line with `timestamp`, `level`, `service`, `environment`, `message`, `channel`, `request_id` (when in a request) and `context`.
- `RedactSensitiveData` masks keys such as `password`, `token`, `authorization`, `cookie`, `api_key`, `app_key`, `secret`.
- Platform classes receive `Psr\Log\LoggerInterface` via constructor injection.

### Health and metrics
- `GET /health/live` → `200 {"status":"ok"}`; no external dependencies.
- `GET /metrics` (Prometheus text format) is served **only** on the `METRICS_ADDR` port (checked against the accepting `SERVER_PORT`, not the `Host` header); it returns 404 on the public listener.
  - `http_requests_total{method,route,status}` and `http_request_duration_seconds{method,route}` (histogram). `route` is the route template or the fixed label `unmatched`; non-standard methods become `OTHER`.
  - PHP runtime gauges: `php_info`, `php_memory_usage_bytes`, `php_memory_peak_usage_bytes`, `php_opcache_*` (when OPcache is enabled; per process).
- **Storage choice:** PHP shares no memory between requests, so samples are kept in a Laravel cache store (`METRICS_STORE`) and updated under a cache lock. `file` is fine locally; use `redis` in staging/production (APCu is not assumed to be installed). The exporter is a small in-repo implementation (`app/Platform/Observability/Metrics`) rather than an extra package.

### Background worker and graceful shutdown
- `php artisan platform:worker` runs `queue:work` (default connection `sync` for now) with `--timeout=SHUTDOWN_TIMEOUT`.
- Laravel's worker handles `SIGTERM`/`SIGINT`/`SIGQUIT` via `pcntl`: it stops taking jobs, finishes the in-flight job and exits `0`. A job exceeding the deadline is killed and the worker exits non-zero, so shutdown cannot hang.
- Lifecycle logs: `worker.started`, `worker.shutdown.started` (with signal), `worker.shutdown.completed` / `worker.shutdown.failed`, `worker.job.timed_out`.
- HTTP: `artisan serve` forwards `SIGTERM`/`SIGINT` to its PHP server with a 10 s grace period; nginx (`SIGQUIT`, `worker_shutdown_timeout`) and PHP-FPM (`SIGQUIT`, `process_control_timeout`) drain in-flight requests — see [`deployments/README.md`](deployments/README.md).
- Startup failures (invalid configuration, boot errors) are logged and artisan / worker exit non-zero.

## Reserved for later stories

| Area | Story |
| --- | --- |
| PostgreSQL connections, migrations, readiness checks | BE-002 |
| Authentication / authorization | BE-003 |
| User module (models, services, routes) under `modules/User` | BE-004+ |
| Events and outbox | BE-010 |
| CI pipelines, architecture checks (Pest Arch / Deptrac) | BE-011 |
| Dockerfile, Compose / Sail, full onboarding environment | BE-012 |

## Project structure

```
app/
  Platform/                 # Shared technical capabilities only (no module dependencies)
    Config/                 # Startup configuration validation
    Console/                # platform:serve, platform:worker
    Http/                   # Middleware, request ID, Problem Details
    Logging/                # JSON formatter, redaction
    Observability/          # Health + metrics
    Queue/                  # Worker lifecycle logging
    PlatformServiceProvider.php
modules/                    # Business modules (Modules\ namespace); User reserved for BE-004
bootstrap/app.php           # Routing, middleware order, exception rendering
bootstrap/providers.php     # Platform + module service providers
config/platform.php         # Platform settings (timeouts, addresses, metrics)
routes/api.php              # /api/v1
routes/platform.php         # /health/live, /metrics
contracts/openapi/          # API contracts (reserved)
database/                   # Reserved for BE-002
deployments/                # nginx / PHP-FPM / supervisor reference configs
tests/Unit, tests/Feature   # Pest
```
