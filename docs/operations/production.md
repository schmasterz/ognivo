# Production Deployment Guide

Step-by-step guide to configure, build, deploy, and operate the **Ognivo** production environment. For prerequisites and initial secret generation, see the [Quickstart Guide](../getting-started/quickstart.md).

---

## 1. Production Security Profile

- **Self-Contained Images:** Built with `composer install --no-dev -o` and `next build` / `next start` (`ognivo-backend:prod`, `ognivo-frontend:prod`). No host source code bind mounts.
- **HTTPS-Only Ingress:** Nginx serves HTTPS on port `443` and redirects port `80` to `443` (except internal `/up` health probes).
- **Zero Internal Port Exposure:** MySQL (`3306`), Redis (`6379`), Reverb (`8080`), `console-proxy` (`8085`), and Next.js (`3000`) are isolated inside the internal Docker network (`app-network`).
- **Automated Startup Guard:** `php artisan app:check-production-config` runs on container startup and halts with exit code `1` if any unsafe defaults are detected (see [configuration.md](configuration.md#5-production-safety-enforcement-appcheck-production-config)).
- **Automatic TLS Bootstrap:** A one-shot `cert-init` service checks `./docker/certs/` and generates a fallback self-signed X.509 certificate (`server.crt` and `server.key`) if none is present.

---

## 2. Configure, Build & Launch

### Step 1: Generate Secrets & Set Your Domain
```bash
# 1. Generate production secrets (.env.production) if not already initialized
make secrets
```

If deploying on a custom domain (e.g., `panel.example.com`), edit `.env.production` **before** building the images:
```ini
APP_URL=https://panel.example.com
REVERB_ALLOWED_ORIGINS=https://panel.example.com
SANCTUM_STATEFUL_DOMAINS=panel.example.com
```
*(Note: `NEXT_PUBLIC_REVERB_APP_KEY` and `NEXT_PUBLIC_API_URL` are embedded into the Next.js bundle at build time.)*

### Step 2: Build & Start the Production Stack
```bash
# 2. Build self-contained production Docker images
make build-prod

# 3. Start the production stack in detached mode
make up-prod
```

> [!TIP]
> If the `ognivo-*:prod` images have never been built on the host, `make up-prod` will build them automatically. However, running `make build-prod` explicitly is recommended whenever deploying code updates or changing `NEXT_PUBLIC_*` variables in `.env.production`.

Verify that all containers report `Up ... (healthy)`:

```bash
make ps
```

- **Web Panel & API:** `https://localhost` *(or your configured `APP_URL`)*
- **Health Endpoint:** `https://localhost/up` (`200 OK`)

---

## 3. Create the Initial Administrator

Database seeders are strictly forbidden in production (`RUN_SEEDERS=false`). Create your initial administrator account via Artisan once `ognivo-backend` is healthy:

```bash
# Generates a random 16-character password and prints it to the terminal
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"

# Or provide an explicit password
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin" --password="YourStrongPasswordHere"
```

This command assigns the `admin` role and unlimited (`∞`) resource quotas.

---

## 4. Installing Real TLS Certificates (Let's Encrypt / Custom CA)

### How Nginx TLS Certificates Work in Production
- In [`docker-compose.prod.yml`](../../docker-compose.prod.yml), the host directory `./docker/certs` is mounted into `cert-init` (read-write) and `ognivo-nginx` at `/etc/nginx/certs` (read-only).
- All `*.crt` and `*.key` files inside `docker/certs/` are git-ignored via [`.gitignore`](../../.gitignore).
- On startup, if `docker/certs/server.crt` or `docker/certs/server.key` is missing or empty, `cert-init` generates a 365-day self-signed certificate (`CN=localhost`) so Nginx can start immediately. If your own certificate files are already in `docker/certs/`, `cert-init` leaves them untouched.

You can replace the self-signed certificate with a real certificate (Let's Encrypt / Certbot, Cloudflare Origin CA, or enterprise CA) using either method below:

### Method A: Place Certificate Files in `docker/certs/` (Recommended)
Copy your full certificate chain (`fullchain.pem`) and private key (`privkey.pem`) directly into `./docker/certs/` on the host and reload Nginx with zero downtime:

```bash
cp /path/to/fullchain.pem docker/certs/server.crt
cp /path/to/privkey.pem docker/certs/server.key
chmod 600 docker/certs/server.key
chmod 644 docker/certs/server.crt

# Reload Nginx inside the running container (zero downtime)
docker exec ognivo-nginx nginx -s reload
```

> [!TIP]
> **Certbot Renew Hook:** If using Certbot on the host, you can automate renewals with a `--deploy-hook` script that copies the renewed `fullchain.pem` and `privkey.pem` to `/path/to/ognivo/docker/certs/server.crt` and `server.key` and runs `docker exec ognivo-nginx nginx -s reload`.

### Method B: Mount Let's Encrypt Directories via `docker-compose.prod.override.yml`
The root [`Makefile`](../../Makefile) automatically detects and includes `docker-compose.prod.override.yml` if present in the repository root. To bind-mount certificates directly from `/etc/letsencrypt`:

1. Ensure dummy/non-empty files exist in `./docker/certs/` (or let `cert-init` run once) so `cert-init` completes cleanly.
2. Create `docker-compose.prod.override.yml` in the repository root:
   ```yaml
   services:
     webserver:
       volumes:
         - ./docker/nginx.prod.conf:/etc/nginx/conf.d/default.conf:ro
         - /etc/letsencrypt/live/panel.example.com/fullchain.pem:/etc/nginx/certs/server.crt:ro
         - /etc/letsencrypt/live/panel.example.com/privkey.pem:/etc/nginx/certs/server.key:ro
   ```
3. Apply the override:
   ```bash
   make up-prod
   ```

### Regenerating the Default Self-Signed Certificate
If you ever want to revert to a fresh self-signed certificate for local testing:
```bash
rm -f docker/certs/server.crt docker/certs/server.key
make up-prod
```

---

## 5. Day-2 Operations Quick Reference

| Operation | Command |
| :--- | :--- |
| **View Container Status** | `make ps` |
| **Stream Production Logs** | `make logs-prod` |
| **Rebuild Production Images** | `make build-prod && make up-prod` |
| **Stop Production Stack** | `make down-prod` |
| **Reload Nginx TLS Certificates** | `docker exec ognivo-nginx nginx -s reload` |
| **Verify Audit Log Seals** | `docker exec -i ognivo-backend php artisan audit:verify` |
| **Rotate `APP_KEY` & Re-encrypt Secrets** | See [configuration.md — Key Rotation](configuration.md#6-app_key-rotation-procedure) |
| **Rotate Audit HMAC Key** | See [security-and-audit.md — HMAC Rotation](../architecture/security-and-audit.md#4-hmac-sealing-key-rotation-procedure) |
