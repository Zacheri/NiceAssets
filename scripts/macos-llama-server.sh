#!/usr/bin/env bash
#
# Nice Assets — host-native llama-server for macOS.
#
# The Docker container cannot use the Apple GPU (Docker Desktop has no Mac
# GPU passthrough), so for fast inference run llama-server natively on the
# host and point Nice Assets at it:
#
#   1. brew install llama.cpp
#   2. scripts/macos-llama-server.sh /path/to/model.gguf
#   3. Nice Assets → Assistant tab → Model panel: enable "External server",
#      host = host.docker.internal, port = 8082.
#
# This script runs on the host, NOT inside the container.

set -euo pipefail

PORT="${PORT:-8082}"

MODEL="${1:-}"
if [[ -z "$MODEL" ]]; then
  MODEL="$(ls ./storage/models/*.gguf 2>/dev/null | head -n 1 || true)"
fi
if [[ -z "$MODEL" || ! -f "$MODEL" ]]; then
  echo "No model found. Put a .gguf file in ./storage/models or pass a path:" >&2
  echo "  $0 /path/to/model.gguf" >&2
  exit 1
fi

if ! command -v llama-server >/dev/null 2>&1; then
  echo "llama-server not found on PATH." >&2
  echo "Install it with: brew install llama.cpp" >&2
  exit 1
fi

echo "Starting llama-server on 127.0.0.1:${PORT} with ${MODEL}"
echo "Now in Nice Assets → Assistant tab → Model panel: enable 'External server', host = host.docker.internal, port = ${PORT}."

exec llama-server --host 127.0.0.1 --port "$PORT" -m "$MODEL" -ngl 99 -c 8192
