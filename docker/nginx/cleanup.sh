#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "=== nginx: clearing log files ==="
find logs -name '*.log' -type f -delete
echo "=== nginx: done ==="
