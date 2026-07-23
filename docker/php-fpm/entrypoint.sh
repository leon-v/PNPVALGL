#!/bin/bash
set -e

# ── Local auth key — generate once, persist across restarts ─────────────────
# The key file lives inside the bind-mounted docker/generated/ directory.
# It persists across container rebuilds and is visible to all services.

KEY_FILE="${LOCAL_AUTH_KEY_FILE:-/run/container.env/.env.key}"

if [[ ! -f $KEY_FILE ]] || ! grep -q '^LOCAL_AUTH_KEY=.' "$KEY_FILE" 2>/dev/null; then
    printf 'LOCAL_AUTH_KEY=%s\n' "$(openssl rand -hex 32)" > "$KEY_FILE"
fi

# Export so the PHP process inherits it even on first start when
# docker compose has already read the (then-empty) env_file.
if [[ -f $KEY_FILE ]]; then
    set -a
    # shellcheck source=/dev/null
    source "$KEY_FILE"
    set +a
fi

# ── Composer dependencies ─────────────────────────────────────────────────
# Install PHP dependencies on every container start. Idempotent and fast
# when composer.lock hasn't changed.

composer install --no-interaction --prefer-dist

# Hand off to the original PHP entrypoint, forwarding any CMD args.
exec /usr/local/bin/docker-php-entrypoint "$@"
