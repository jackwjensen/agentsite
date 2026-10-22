# Deploying

The site is one container serving plain HTTP on port 80. Everything below is
about putting something in front of it that terminates TLS.

There is no build step, so a deploy is: get the files onto the server, and
restart the container. Rollback is `git checkout` the previous commit and
restart.

## The container

```bash
git clone <your-repo> /srv/mysite
cd /srv/mysite
cp .env.example .env        # edit it
docker compose up -d --build
```

For production, activate the overlay by putting this in `.env`:

```
COMPOSE_FILE=docker-compose.yml:docker-compose.production.yml
```

(On Windows the separator is `;` rather than `:`.)

The overlay publishes on `127.0.0.1:8080` instead of all interfaces, so only
a reverse proxy on the same host can reach it, and sets `restart: always`.

## A reverse proxy in front

Pick one. All three assume the container is on `127.0.0.1:8080`.

**Caddy** — the shortest path to working TLS:

```
example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy obtains and renews the certificate itself. Nothing else to configure.

**nginx**:

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $http_host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Traefik** — add labels to the `web` service:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.mysite.rule=Host(`example.com`)"
  - "traefik.http.routers.mysite.tls.certresolver=letsencrypt"
  - "traefik.http.services.mysite.loadbalancer.server.port=80"
```

### The two headers that matter

Whatever proxy you use, it must send:

- **`X-Forwarded-Proto`** — the site uses it to decide whether the request
  really arrived over TLS. Without it, session cookies lose their `Secure`
  flag and generated absolute URLs say `http://`.
- **`X-Forwarded-For`** (or Cloudflare's `CF-Connecting-IP`) — the site uses
  it to identify the visitor. Without it, every rate limit and every logged
  IP is the proxy's own address, which means one visitor can exhaust
  everybody's allowance.

If you also put a CDN in front, check that a long-lived `Cache-Control` on
`/assets/` survives it. Asset URLs here are versioned by file modification
time, so caching them for a year is safe — but only if the header actually
reaches the browser.

## Without Docker

This runs where WordPress runs. Any host with PHP 8.5 and nginx or Apache — keep
that PHP on its latest patch release, since nothing here does it for you:

1. Upload the repository.
2. Point the document root at `public/`.
3. Route everything that is not a real file to `public/index.php`.
4. Make `_logs/` writable by the web server user.

`docker/nginx.conf` is a working reference for step 3, including the security
headers, the dotfile block and the rule that leaves dotted paths alone (which
is what keeps `/feed.xml` and `/about.md` working).

## Deploying changes

```bash
cd /srv/mysite && git pull && docker compose build --pull && docker compose up -d
```

`--pull` is what keeps PHP patched. The Dockerfile follows the floating
`php:8.5-fpm-alpine` tag, which Docker Hub rebuilds for every PHP release,
but a plain `--build` reuses the base image the server cached on its first
deploy — so without `--pull` the site never gets a PHP security fix, however
often you deploy. `docker compose exec web php -v` shows what is running.

Because there is no database and no generated state, that is atomic enough in
practice, and rollback is:

```bash
git reset --hard <previous-commit> && docker compose up -d --force-recreate
```

A worked GitHub Actions example is in
[examples/deploy.yml](examples/deploy.yml). It is an example, not a wired-up
workflow: copy it to `.github/workflows/`, fill in the placeholders, and read
it before you trust it with SSH access to your server.

## Before you go live

- [ ] `site.url` in `config/site.php` is your real domain, not `example.com`
- [ ] `./dev.sh check` passes
- [ ] The privacy policy is yours — search for `REPLACE ME`
- [ ] `.env` has real values, and is **not** committed
- [ ] For each installed module, its `MODULE.md` production section is done
- [ ] `curl -I https://yourdomain/` shows the security headers
- [ ] `https://yourdomain/sitemap.xml` lists the URLs you expect
- [ ] `curl -H 'Accept: text/markdown' https://yourdomain/` returns markdown
- [ ] Retention cron is scheduled, if a module needs one
