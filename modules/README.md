# Modules

Business modules live here, one directory per bounded context, autoloaded as
`Modules\<Name>\` (see `composer.json`). Each module owns its:

- `ServiceProvider` (registered in `bootstrap/providers.php`)
- routes, mounted under `/api/v1` from the module's provider
- migrations and database connection (ADR-001, BE-002)

Modules may depend on `app/Platform`; `app/Platform` must never depend on a module.
