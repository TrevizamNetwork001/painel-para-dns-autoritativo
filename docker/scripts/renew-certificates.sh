#!/usr/bin/env bash
set -euo pipefail

cd /opt/dns-center

docker run --rm \
  -v "$PWD/docker/certbot/www:/var/www/certbot" \
  -v "$PWD/docker/certbot/conf:/etc/letsencrypt" \
  certbot/certbot:latest renew \
  --webroot \
  --webroot-path=/var/www/certbot \
  --quiet

docker compose exec -T web nginx -t
docker compose exec -T web nginx -s reload
