# Production deploy

Production runs as one docker compose project (`production-pos`) on a shared DigitalOcean droplet
under `/opt/production-pos`. The droplet hosts other production systems, so:

- every service has a `mem_limit`, and nothing publishes a port;
- traffic arrives only through the Cloudflare Tunnel `production-pos` (the `tunnel` service), which
  serves `pos.kgarden.co.th` — no DNS or reverse-proxy changes on the droplet are needed.

Never run a second connector for the same tunnel against another database (e.g. the LAN stack):
Cloudflare would split traffic between two copies of the data.

## Files on the server

| Path | What |
|---|---|
| `/opt/production-pos/docker-compose.yml` | copy of [`docker-compose.yml`](docker-compose.yml) |
| `/opt/production-pos/.env` | app `.env` + `DB_ROOT_PASSWORD` + `TUNNEL_TOKEN` (see [`.env.example`](.env.example)), mode 600 |
| `/opt/production-pos/backups/` | database dumps |

## Deploy a new version

```bash
# 1. build locally (PHP 8.4: composer.lock does not allow 8.5 yet)
cd point-of-sales
docker build --platform linux/amd64 -f docker/Dockerfile -t production-pos-app:latest .

# 2. back up first when the release has migrations
ssh <droplet> 'cd /opt/production-pos && docker compose exec -T mysql sh -c \
  "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4 \"\$MYSQL_DATABASE\"" \
  | gzip > backups/pre-$(date +%Y%m%d-%H%M).sql.gz'

# 3. ship the image and restart (the entrypoint runs migrations + route/view/event caches)
docker save production-pos-app:latest | gzip -1 | ssh <droplet> 'gunzip | docker load'
ssh <droplet> 'cd /opt/production-pos && docker compose up -d app scheduler'

# 4. verify
ssh <droplet> 'cd /opt/production-pos && docker compose exec -T app php artisan inventory:ledger-check'
```

Test migrations against MySQL before shipping — the PHPUnit suite runs on SQLite and misses
MySQL-only limits such as the 64-character identifier length.

## Tests

```bash
cd point-of-sales
docker build -f docker/Dockerfile.test -t production-pos-test .
docker run --rm --entrypoint php -e APP_ENV=testing production-pos-test vendor/bin/phpunit
```
