# Installation Guide

How to get **Ognivo** running, from `git clone` to a working panel. This guide explains what each step does and what to check. The [README](../../README.md#installation) has the short version.

Choose your path:

| You want to... | Use | Time |
| :--- | :--- | :--- |
| Try Ognivo, develop, or run tests on your own machine | [Development install](#4-development-install) | Several minutes (first image build) |
| Run a panel on a server that other people can reach | [Production install](#5-production-install) | About 15 minutes plus DNS and certificate work |

Both paths use Docker Compose. You do **not** need PHP, Node.js, MySQL or Redis on the host.

---

## 1. What Gets Installed

One Compose project named `ognivo` with these services (names differ only by a `-dev` suffix in development):

| Service | Role |
| :--- | :--- |
| `webserver` (Nginx) | Public entry point. Routes `/` to the frontend, `/api` to the backend, `/app` to Reverb and `/console` to the console proxy |
| `backend` (Laravel, PHP-FPM) | API |
| `frontend` (Next.js) | Web UI |
| `queue-worker`, `scheduler`, `incus-events` | Background jobs, scheduled tasks and hypervisor event listener |
| `reverb` | WebSocket server for live updates |
| `console-proxy` (Node.js) | Web terminal bridge to Incus |
| `db` (MySQL 8.4), `redis` (Redis 7) | Data stores |
| `cert-init` (production only) | One-shot job that creates a self-signed TLS certificate if none exists |

The Compose project name is fixed to `ognivo`, so **the name of the folder you clone into does not matter**. Containers and volumes are always named `ognivo-*` and `ognivo_*`.

---

## 2. Requirements

### Host software

| Requirement | Version | Why |
| :--- | :--- | :--- |
| Docker Engine | 24.0 or newer | Runs the stack |
| Docker Compose | v2, 2.20 or newer (`docker compose`, not `docker-compose`) | Orchestration |
| GNU Make | any recent | The `Makefile` wraps all workflows |
| Bash and OpenSSL | any recent | `scripts/gen-secrets.sh` generates random secrets |
| Git | any recent | To clone the repository |

Check what you have:

```bash
docker --version
docker compose version
make --version
openssl version
git --version
```

Notes:

- **Linux** is the recommended platform. macOS works for development.
- **Windows:** run everything inside **WSL2** with Docker Desktop's WSL integration, because the scripts need Bash.
- If `docker` only works with `sudo`, add your user to the `docker` group and log in again. The `Makefile` calls `docker` directly.

### Resources

The project does not publish tested minimums. As a starting point, plan for at least 2 CPU cores, 4 GB of RAM and several GB of free disk space for images and volumes, more for production. The first start downloads base images and runs `composer install` and `npm install` in the development stack.

### Network ports (free on the host)

| Environment | Ports |
| :--- | :--- |
| Development | `80` (Nginx), `3000` (Next.js), `8080` (Reverb), `3306` (MySQL, bound to `127.0.0.1` only) |
| Production | `80` (redirects to HTTPS and health probe), `443` (HTTPS). Nothing else is published |

### For production only

- A **server** that you control, with a public IP address.
- A **DNS name** (for example `panel.example.com`) pointing to it, if you want a trusted certificate.
- Outbound access from the server to each Incus node on port `8443`.

### Incus node (optional at first)

You do not need an Incus server to try Ognivo. The development stack seeds fake nodes. To manage real instances you need **Incus 6.0 LTS or newer** (LXD is experimental). See [Connect Your First Incus Node](#6-connect-your-first-incus-node).

---

## 3. Get the Code

```bash
git clone https://github.com/schmasterz/ognivo.git
cd ognivo
```

**A specific version.** `main` moves. To install a released version, list the tags and check one out:

```bash
git tag --list
git checkout <tag>
```

**A ZIP download.** Unpack the archive (for example `ognivo-main.zip`) and `cd` into the extracted folder. Everything below works the same.

---

## 4. Development Install

For local evaluation and development. It seeds demo accounts and fake nodes and reloads code as you edit.

> [!CAUTION]
> **Local machine only.** The development stack has debug tooling and well-known demo passwords (`password`). Nginx, Next.js and Reverb are published on all host interfaces, not only `localhost`. Never run it on a server reachable from untrusted networks.

### Step 1: Start

```bash
make up-dev
```

On a fresh clone this single command:

1. Creates `.env.development` (and `.env.production`) from the `*.example` templates and fills in random secrets. Existing values are never overwritten.
2. Builds the images `ognivo-backend:dev`, `ognivo-frontend:dev` and `ognivo-console-proxy:dev`.
3. Starts MySQL and Redis, then the backend, which installs Composer dependencies into `backend/vendor/`, runs migrations and audit grants, and seeds demo data.
4. Starts the queue worker, scheduler, event listener, Reverb, console proxy, frontend and Nginx.

If a production stack is running on the same machine, it is stopped first so the ports are free.

### Step 2: Wait for it to be healthy

```bash
make ps
make logs-dev      # Ctrl+C to stop following
```

The first start takes a few minutes. Wait until the containers with a healthcheck report `healthy`.

### Step 3: Open the panel

| What | URL |
| :--- | :--- |
| Web panel | `http://localhost` (also `http://localhost:3000`) |
| API documentation (OpenAPI) | `http://localhost/docs/api` |
| Health check | `http://localhost/up` |

| Role | Email | Password |
| :--- | :--- | :--- |
| Administrator | `admin@ognivo.local` | `password` |
| Customer | `customer@ognivo.local` | `password` |

What is seeded (nodes, quota tiers, IP pool, demo instances): [Development Environment](development.md#3-seeded-demo-data).

### Everyday development commands

| Action | Command |
| :--- | :--- |
| Stop | `make down-dev` |
| Rebuild after changing a `Dockerfile` or a package manifest | `make build-dev`, then `make up-dev` |
| Reset the dev database and re-seed | `make reset-dev` (deletes the development database volume) |
| Restrict published ports to localhost | Create `docker-compose.dev.override.yml`; the `Makefile` picks it up automatically |

After editing `.env.development`, run `make up-dev` again so containers are recreated with the new values.

---

## 5. Production Install

A hardened deployment: HTTPS on `443`, separate MySQL users for the application, migrations and the audit log, no internal ports exposed, and a startup check that refuses unsafe configuration.

### Step 1: Generate secrets

```bash
make secrets
```

This creates `.env.production` (and `.env.development`) and fills in random values for `APP_KEY`, the Reverb keys, the MySQL passwords, the Redis password and the audit keys. Running it again never overwrites existing values.

### Step 2: Configure `.env.production`

Edit the file **before building**. The `NEXT_PUBLIC_*` values are baked into the frontend image at build time, so changing them later requires `make build-prod`.

| Setting | What to put |
| :--- | :--- |
| `APP_URL` | The public URL, for example `https://panel.example.com` |
| `REVERB_ALLOWED_ORIGINS` | The same origin, `https://panel.example.com`. A wildcard is rejected in production |
| `SANCTUM_STATEFUL_DOMAINS` | The panel's hostname without scheme, `panel.example.com`. Add the line if it is missing |
| `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS` | `true` if any Incus node is on a private (RFC 1918) or loopback address. Production rejects those by default |
| `MAIL_*` | Optional. Mail falls back to the log until SMTP is configured; see [Configuration](../operations/configuration.md) |

For a local test of the production stack you can leave everything at the defaults (`localhost`).

The complete list is in the [Configuration Reference](../operations/configuration.md).

### Step 3: Build and start

```bash
make build-prod
make up-prod
make ps
```

On first start:

1. `cert-init` creates a self-signed certificate in `./docker/certs/` if `server.crt` and `server.key` are not there yet.
2. The backend runs `php artisan app:check-production-config` and **refuses to start** if it finds a placeholder secret, `APP_DEBUG=true`, `RUN_SEEDERS=true` or a wildcard origin.
3. Migrations and strict audit grants are applied. Seeders are disabled.
4. Nginx starts on `80` (redirects to HTTPS) and `443`.

Wait until the containers report `healthy`. If the backend does not start, read `make logs-prod`; the message says which setting failed.

### Step 4: Create the first administrator

Production has no demo accounts:

```bash
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"
```

Without `--password`, a strong 16-character password is generated and printed once. Store it safely.

### Step 5: Open the panel

Open your `APP_URL` (for a local test: `https://localhost`) and sign in. The health check is `/up`.

### TLS certificates

By default Nginx serves a self-signed certificate (valid 365 days, `CN=localhost`), so browsers show a warning. To install a real certificate (Let's Encrypt, a custom CA, and so on), copy the full chain and key into `docker/certs/` and reload Nginx:

```bash
cp /path/to/fullchain.pem docker/certs/server.crt
cp /path/to/privkey.pem   docker/certs/server.key
chmod 600 docker/certs/server.key
chmod 644 docker/certs/server.crt
docker exec ognivo-nginx nginx -s reload
```

`cert-init` never overwrites certificate files that already exist. For automatic renewal and a Let's Encrypt directory mount, see [Production Deployment](../operations/production.md#4-installing-real-tls-certificates-lets-encrypt--custom-ca).

### Firewall

- **Inbound:** allow `80` and `443` only. Nothing else is published on the host in production.
- **Outbound:** the server must reach every Incus node on TCP `8443`.
- Do not expose MySQL, Redis, Reverb or the console proxy; they are internal to the Docker network.

### Back up what cannot be regenerated

- **`.env.production`**, especially `APP_KEY` and `AUDIT_HMAC_KEY`. Losing `APP_KEY` makes encrypted data (the panel's mTLS key, node tokens, the SMTP password) unreadable.
- The MySQL data volume (`ognivo_db_data`).
- `docker/certs/` if you installed your own certificate.

---

## 6. Connect Your First Incus Node

Ognivo manages hypervisors, so this is the step that makes the panel useful.

**On the Incus host:**

```bash
# Make the Incus API reachable by the panel (by default it is local-only)
incus config set core.https_address <node-ip>:8443

# Create a one-time trust token for the panel (prints the token)
incus config trust add ognivo-panel
```

On LXD, create the token with `lxc config trust add --name ognivo-panel`.

**In the admin UI:** **Compute Nodes**, **Create Node**, tab **Connect with token**. Enter a name, paste the token and set the capacity, then click **Connect with token**. Ognivo compares the server certificate fingerprint with the one in the token before sending any secret, then pins it for future connections.

Requirements for this to work:

- The panel's containers must be able to reach `<node-ip>:8443`. If Incus runs on the same machine as the panel, use an address that containers can route to; `localhost` inside a container is the container itself.
- In production, a node on a private or loopback address needs `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS=true` (then run `make up-prod` to apply it).
- For Routed networking the node needs IP forwarding and Proxy ARP: [Networking & IPAM](../features/networking.md).

The alternative flow with a pre-trusted certificate, and the meaning of each connection state, is in [Host Onboarding & mTLS](../features/host-onboarding.md).

---

## 7. Verify the Installation

| Check | Command or action | Expected |
| :--- | :--- | :--- |
| Containers | `make ps` | All containers running; those with healthchecks `healthy` |
| Health endpoint | open `/up` on your URL | A success response |
| Sign in | log in with the admin account | The admin dashboard |
| Audit log | `docker exec -it ognivo-backend php artisan audit:verify` (production) | Verification succeeds |
| Node | **Verify connection** on a host | State `ok` |

---

## 8. Stop, Update and Remove

### Stop

```bash
make down-dev     # or: make down-prod
```

This removes the containers but **keeps the data volumes**.

### Update

Before 1.0, an update may require a fresh install. Read the release notes in [`CHANGELOG.md`](../../CHANGELOG.md) first, and back up (see above). A typical update of an existing install, when the release notes say it is supported:

```bash
git fetch --tags
git checkout <new-tag>        # or: git pull, if you follow main
make build-prod
make up-prod
```

If a release changes database privileges, the notes will tell you to re-apply the audit grants:

```bash
docker exec -it ognivo-backend php artisan audit:grants --force
```

### Remove everything

> [!CAUTION]
> This permanently deletes the database. There is no undo.

```bash
make down-dev
make down-prod
docker volume ls | grep ognivo       # review the list first
docker volume rm <volume-name>       # remove the ones you want gone
```

Dev data lives in `ognivo_db_data_dev`, production data in `ognivo_db_data`.

---

## 9. Troubleshooting the Installation

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `bind: address already in use` | Another program uses port `80`, `443`, `3000`, `8080` or `3306` | Find it with `sudo lsof -i :80` (or the port), stop it, run `make up-dev` / `make up-prod` again |
| `Production configuration check failed` | A placeholder or unsafe value in `.env.production` | Read `make logs-prod`, fix the named setting. List of checks: [Configuration](../operations/configuration.md#5-production-safety-enforcement-appcheck-production-config) |
| MySQL `Access denied for user 'ognivo_user'` | New passwords were generated but the existing database volume still has the old ones | Development: `make reset-dev`. Production with no data to keep: `make down-prod`, `docker volume rm ognivo_db_data`, `make up-prod` |
| Browser warns about the certificate | Self-signed certificate | Install a real one, see [TLS certificates](#tls-certificates) |
| Page loads but live updates or the terminal do not work | `REVERB_ALLOWED_ORIGINS` or `APP_URL` does not match the address in the browser | Make them equal to the public URL, rebuild the frontend (`make build-prod`) and run `make up-prod` |
| `502 Bad Gateway` after recreating containers | Nginx kept an old container address | `docker restart ognivo-nginx` (or `ognivo-nginx-dev`) |
| Adding an Incus node fails or is rejected | Port `8443` not reachable, Incus listens only locally, node on a private address in production, or the trust token expired | Check `core.https_address`, firewall and `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS`; create a new token |

> [!WARNING]
> Do not delete `.env.production` and run `make secrets` on an installation that already has data. That generates a new `APP_KEY` and new database passwords that no longer match the existing volume.

More solutions: [Troubleshooting](../operations/troubleshooting.md).

---

## 10. Where to Go Next

- [Quickstart](quickstart.md): a shorter walkthrough of the same steps.
- [Development Environment](development.md): topology, seeded data, local ports.
- [Production Deployment](../operations/production.md): TLS options and day-2 operations.
- [Configuration Reference](../operations/configuration.md): every environment variable.
- [Commands Reference](../operations/commands.md): Make, Docker and Artisan commands.
- [Host Onboarding & mTLS](../features/host-onboarding.md): connecting Incus and LXD nodes.