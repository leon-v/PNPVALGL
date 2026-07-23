#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

echo "This will stop all containers, remove volumes, and delete all host log files."
read -rp "Are you sure you want to continue? [y/N] " REPLY

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

echo "=== Stopping containers and removing volumes ==="

# Root compose file.
docker compose down -v --rmi local --remove-orphans

# Run per-container cleanup scripts.
echo "=== Clearing host log files ==="
for cleanup in docker/*/cleanup.sh; do
    if [[ -x $cleanup ]]; then
        echo "--- $(dirname "$cleanup") ---"
        "$cleanup"
    fi
done

echo "=== Clearing shared auth key ==="
:> docker/generated/.env.key

echo "=== Done ==="
echo "Pulled images are cached. Locally built images removed — will rebuild on next up."
echo "Run 'docker compose up -d' to restart."
