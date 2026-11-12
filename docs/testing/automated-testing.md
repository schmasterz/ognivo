# Automated Testing & Static Analysis

Ognivo includes a multi-layered automated test suite covering backend unit/feature tests, real MySQL/Redis concurrency integration tests, PHPStan & Psalm static analysis, `console-proxy` tests, and Next.js frontend checks.

---

## 1. Backend Unit & Feature Tests (SQLite)

Standard unit and feature tests run out-of-the-box on in-memory SQLite (`:memory:`) without requiring external database containers:

```bash
# Via running dev container
docker exec ognivo-backend-dev php artisan test --exclude-group=integration

# Or via ephemeral container
docker run --rm -v $(pwd)/backend:/var/www/html -w /var/www/html ognivo-backend:dev vendor/bin/pest --exclude-group=integration
```

> [!NOTE]
> If MySQL/Redis test containers are not running and you run `pest` without `--exclude-group=integration`, integration tests automatically skip via `skipUnlessRealInfrastructure()`.

---

## 2. Backend Integration Tests (Real MySQL 8.4 & Redis 7)

SQLite does not exercise `SELECT ... FOR UPDATE` row locks (`lockForUpdate()`), MySQL unique constraints under multi-process concurrency, or real Redis queue worker timeout semantics. The `integration` test group validates these against real MySQL 8.4 and Redis 7.4 containers (including 8-process parallel IP allocation via `proc_open`).

### Step 1: Start Isolated Test Infrastructure
```bash
docker compose -f docker-compose.test.yml up -d
```
Starts `ognivo-test-mysql` (`:3306`) and `ognivo-test-redis` (`:6379`). Custom host ports can be passed via `TEST_DB_PORT` and `TEST_REDIS_PORT`.

### Step 2: Run Migrations & Execute Integration Suite
```bash
# 1. Migrate the test database
docker run --rm --net=host \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=127.0.0.1 \
  -e DB_PORT=3306 \
  -e DB_DATABASE=ognivo_test \
  -e DB_USERNAME=root \
  -e DB_PASSWORD="" \
  -v $(pwd)/backend:/var/www/html \
  -w /var/www/html \
  ognivo-backend:dev php artisan migrate --force

# 2. Run the integration test suite
docker run --rm --net=host \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=127.0.0.1 \
  -e DB_PORT=3306 \
  -e DB_DATABASE=ognivo_test \
  -e DB_USERNAME=root \
  -e DB_PASSWORD="" \
  -e REDIS_HOST=127.0.0.1 \
  -e REDIS_PORT=6379 \
  -e REDIS_CLIENT=predis \
  -e CACHE_STORE=redis \
  -e QUEUE_CONNECTION=redis \
  -v $(pwd)/backend:/var/www/html \
  -w /var/www/html \
  ognivo-backend:dev vendor/bin/pest --configuration=phpunit.integration.xml --group=integration
```

### Step 3: Tear Down Test Infrastructure
```bash
docker compose -f docker-compose.test.yml down -v
```

---

## 3. Backend Static Analysis (PHP 8.5)

### PHPStan + Larastan (Level 10 — Maximum Strictness)
```bash
docker exec ognivo-backend-dev vendor/bin/phpstan analyse --memory-limit=1G
```

### Vimeo Psalm (ErrorLevel 1)
```bash
docker exec ognivo-backend-dev vendor/bin/psalm --no-cache
```

---

## 4. Frontend & Console Proxy Verification

Run from `frontend/` (or via `docker exec ognivo-frontend-dev <cmd>`):

```bash
# 1. Regenerate Next.js route types & run strict TypeScript check
npx next typegen && npx tsc --noEmit -p . --incremental false

# 2. Run ESLint (0 errors required)
npx eslint src

# 3. Run frontend unit tests via native Node test runner
node --test src/lib/hooks/__tests__/useConfirmDialog.test.ts src/lib/hooks/__tests__/useCachedFetch.test.ts src/components/audit/__tests__/audit.test.ts

# 4. Verify production Next.js Turbopack build
npm run build
```

Run `console-proxy` unit tests from `console-proxy/`:
```bash
npm test
```

For manual browser verification workflows and cURL recipes, see [manual-qa.md](manual-qa.md).
