#!/usr/bin/env bash
# Regenerates the README screenshots (screenshots/*.png) from a throwaway
# instance: builds the prod image from this checkout, starts it with
# compose.yml on an empty database, fills it with fictional data (seed.py)
# and photographs it with Playwright. Nothing touches your dev database.
#
#   scripts/demo/run.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
PORT=8099
PROJECT=firefunds-demo
trap 'cd "$WORK" && docker compose -p $PROJECT down -v >/dev/null 2>&1; docker run --rm -v "$WORK":/w alpine rm -rf /w/data; rm -rf "$WORK"' EXIT

docker build -q --target prod -t ghcr.io/nicolopozzato/firefunds:demo "$ROOT"

cp "$ROOT/compose.yml" "$WORK/"
cat > "$WORK/.env" <<ENV
POSTGRES_PASSWORD=$(openssl rand -hex 16)
APP_SECRET=$(openssl rand -hex 16)
JWT_PASSPHRASE=$(openssl rand -hex 16)
PORT=$PORT
FIREFUNDS_TAG=demo
ENV
cd "$WORK"
docker compose -p $PROJECT up -d
until curl -sf "http://127.0.0.1:$PORT/api/health" >/dev/null; do sleep 2; done

python3 "$ROOT/scripts/demo/seed.py" "http://127.0.0.1:$PORT"

mkdir -p "$ROOT/screenshots"
docker run --rm --network host --user "$(id -u):$(id -g)" -e HOME=/tmp \
	-v "$ROOT/scripts/demo:/scripts:ro" -v "$ROOT/screenshots:/out" \
	mcr.microsoft.com/playwright/python:v1.58.0-noble \
	sh -c "pip install -q --user playwright==1.58.0 2>/dev/null; python3 /scripts/screenshots.py http://127.0.0.1:$PORT /out"
