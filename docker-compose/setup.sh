#!/bin/sh

set -e
cd "$(dirname "$0")/.."

# Build on the shared host directory; Compose mounts it over the PHP image.
docker run --rm --user "$(id -u):$(id -g)" \
  --env npm_config_cache=/tmp/npm-cache \
  --volume "$PWD:/app" --workdir /app \
  node:24-bookworm-slim sh -c 'npm ci && npm run build'

docker-compose exec app composer install --no-interaction --prefer-dist --optimize-autoloader

docker-compose exec app php artisan storage:link || true
docker-compose exec app php artisan key:generate
