#!/bin/bash
set -e

# ── Local auth key — generate once, persist across restarts ─────────────────
# If the shared .env.key file is empty, fill it with a secure random
# value for LOCAL_AUTH_KEY. The file is bind-mounted so the value
# survives container rebuilds and is visible to other containers.

KEY_FILE="${LOCAL_AUTH_KEY_FILE:-/run/container.env.key}"

if [[ -f $KEY_FILE ]] && ! grep -q '^LOCAL_AUTH_KEY=.' "$KEY_FILE" 2>/dev/null; then
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
