#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
python3 -m peaklink.server.app --host 0.0.0.0 --port "${1:-8787}"
