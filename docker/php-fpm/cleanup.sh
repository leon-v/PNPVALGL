#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "=== php-fpm: clearing log files ==="
find logs -name '*.log' -type f -delete
find logs -name '*.log.json' -type f -delete

echo "=== php-fpm: clearing cache ==="
find ../../app/storage/cache -not -name '.gitkeep' -type f -delete

echo "=== php-fpm: clearing auth key ==="
:> ../.env.key

echo "=== php-fpm: done ==="
