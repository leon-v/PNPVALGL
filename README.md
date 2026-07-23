# PNPVALGL — PHP Web Stack Template

Docker Compose stack for PHP 8.5 and Postgres with Grafana for observability.
Drops into any PHP project and gives you a running dev environment with structured logging, metrics-ready dashboards, and sensible development defaults.

## Services

| Service   | Image                   | Role                          | Port  |
| --------- | ----------------------- | ----------------------------- | ----- |
| `php`     | `php:8.5-fpm` (built)   | PHP-FPM with Composer         | —     |
| `nginx`   | `nginx`                 | Reverse proxy + static files  | 8080  |
| `postgres`| `postgres:18`           | Relational database           | —     |
| `valkey`  | `valkey/valkey:9-trixie`| ValKey (Redis-compatible)     | —     |
| `alloy`   | `grafana/alloy:latest`  | Log tailing + forwarding      | —     |
| `loki`    | `grafana/loki:3.7.0`    | Log aggregation               | —     |
| `grafana` | `grafana/grafana:latest`| Dashboards                    | 3000  |

## Quick start

```bash
# Start everything
docker compose up -d
```

## Links

- [App](http://localhost:8080) — the PHP application
- [Grafana](http://localhost:3000) — dashboards (anonymous access)

## What's inside

PHP 8.5 FPM with pdo_pgsql, pgsql, redis, xdebug, uopz, and Composer.
Xdebug is in trigger mode (set `XDEBUG_TRIGGER` to activate), not always-on.
Nginx reverse proxy. PostgreSQL 18 with `pg_stat_statements` (query profiling)
and `pg_trgm` (fuzzy text search) extensions enabled.
Valkey (Redis-compatible).

Logging path: PHP errors, exceptions, and `syslog()` calls are intercepted by
LokiLogger via `auto_prepend_file` and pushed directly to Loki's HTTP API —
they do not pass through Alloy. Everything else — nginx access/error logs,
PHP-FPM daemon logs, and Postgres jsonlog files — is tailed by Alloy, which
parses the entries, attaches severity labels, and forwards them to Loki.

Grafana with anonymous access and pre-provisioned dashboards.

## Configuration

Copy `docker/.env.template` to `docker/.env` and edit the values for your project.
Use `docker/.env.local` to override values without touching `.env` — it is loaded
after `.env` and takes precedence. Compose loads them in order: `.env.template`
(defaults) → `.env` (your values) → `.env.local` (overrides).

A `LOCAL_AUTH_KEY` is auto-generated into `docker/generated/.env.key` on first container
start and persists across rebuilds. It is shared to all services that mount
that file.

## Renaming the project

`docker/rename.sh` renames the project from `PNPVALGL` to a name of your choice in
every file under version control. It handles all three case variants —
UPPERCASE, lowercase, and Titlecase — so references in code, config, docs, and
env files stay consistent. Skips `vendor/`, `.git/`, `docker/*/logs/`, the
rename script itself, and binary files.

```bash
./docker/rename.sh MYNEWNAME
```

## Cleaning up

> [!WARNING]
> This wipes Postgres data, Valkey cache, Loki logs, Grafana runtime data
> (including dashboards created through the UI), and Alloy state. Clears all
> host log files. The locally-built PHP image is removed (rebuilds on next
> `up`); pre-built images (nginx, postgres, etc.) stay cached.

```bash
./docker/cleanup.sh
```

## License

MIT — use it however you like.
