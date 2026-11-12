# Ognivo Frontend

Next.js 16 (App Router, React 19, TypeScript, Tailwind CSS v4) web dashboard for the **Ognivo** cloud control plane.

## Documentation

- **Frontend Architecture & Coding Guidelines:** See [`AGENTS.md`](AGENTS.md) for route group structure (`(auth)` and `(dashboard)`), state hooks, caching rules, and strict React 19 / TypeScript conventions.
- **Platform Documentation:** See the root [`README.md`](../README.md) and [`docs/`](../docs/README.md) for full-stack setup and operations.

## Verification & Local Checks

When running inside the workspace or the `ognivo-frontend-dev` container:

```bash
# 1. Regenerate Next.js route types & run TypeScript compiler check
npx next typegen && npx tsc --noEmit -p . --incremental false

# 2. Run ESLint (strict zero-error policy)
npx eslint src

# 3. Run unit tests via native Node.js test runner
node --test src/lib/hooks/__tests__/useConfirmDialog.test.ts src/lib/hooks/__tests__/useCachedFetch.test.ts src/components/audit/__tests__/audit.test.ts

# 4. Run production build check
npm run build
```
