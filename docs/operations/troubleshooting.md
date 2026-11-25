# Troubleshooting

Solutions to common issues encountered when starting or operating **Ognivo**.

---

## 1. Production Configuration Check Failed

**Error:**
```text
Production configuration check failed! Refusing to start with unsafe defaults.
```

**Cause:** `.env.production` contains a placeholder (`CHANGE_ME`), a default password (`secret_password`), `APP_DEBUG=true`, `RUN_SEEDERS=true`, or wildcard `*` in `REVERB_ALLOWED_ORIGINS`. See the full check list in [configuration.md](configuration.md#5-production-safety-enforcement-appcheck-production-config).

**Fix:** Update the offending settings in `.env.production` directly (for example `APP_DEBUG=false`, `RUN_SEEDERS=false`, `REVERB_ALLOWED_ORIGINS`, `APP_URL`, or any default passwords), run `make secrets` to idempotently populate missing or `CHANGE_ME` secrets, and restart the production stack:
```bash
make secrets
make up-prod
```

> [!CAUTION]
> Do not delete `.env.production` on an existing install, as this regenerates `APP_KEY` and renders existing encrypted data unrecoverable.

---

## 2. Port Conflict (`bind: address already in use`)

**Cause:** A host process (e.g., local Apache/Nginx or host MySQL daemon) is already bound to port `80`, `443`, `3000`, `8080`, or `3306`.

**Fix:** Identify and stop the conflicting host service:
```bash
sudo lsof -i :80
sudo lsof -i :443
sudo lsof -i :3306
```
*(Note: Switching between `make up-dev` and `make up-prod` automatically stops the opposite Ognivo environment.)*

---

## 3. MySQL `Access denied for user 'ognivo_user'`

**Cause:** New passwords were generated in `.env.development` or `.env.production`, but the persistent MySQL volume (`db_data`) was already initialized with older credentials.

**Fix (Development — wipes local dev database):**
```bash
make reset-dev
```

**Fix (Production — only if initializing a fresh deployment with no data to keep):**
```bash
make down-prod
docker volume rm ognivo_db_data
make up-prod
```

---

## 4. Nginx `502 Bad Gateway` After Container Recreation

**Cause:** Upstream container IP addresses changed on the Docker bridge network and Nginx cached the previous IP.

**Fix:** Restart the Nginx container:
```bash
docker restart ognivo-nginx-dev   # Development
docker restart ognivo-nginx       # Production
```

---

## 5. Inspecting Service Logs

To diagnose failures in specific background daemons:
```bash
docker logs -f ognivo-backend-dev
docker logs -f ognivo-queue-dev
docker logs -f ognivo-incus-events-dev
docker logs -f ognivo-console-proxy-dev
docker logs -f ognivo-frontend-dev
```
*(Omit the `-dev` suffix when inspecting production containers. See [commands.md](commands.md#5-container-names-reference) for all container names.)*

---

## 6. Encryption & Key Rotation Issues

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `Illuminate\Contracts\Encryption\DecryptException: The MAC is invalid` | `APP_PREVIOUS_KEYS` is missing or does not include the key that encrypted the database record. | Follow the rollback and recovery procedure in [configuration.md#6-app_key-rotation-procedure](configuration.md#6-app_key-rotation-procedure). |
| `app:reencrypt-secrets --status` reports undecryptable rows or exits 1 | Incomplete rotation, wrong previous key, or database restore without the matching `APP_KEY`. | Check container environment variables, ensure `APP_PREVIOUS_KEYS` has all previous keys, and consult [configuration.md#6-app_key-rotation-procedure](configuration.md#6-app_key-rotation-procedure). |
