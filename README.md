# Ognivo

Formerly known as lxd-manager.

Control plane for Incus & LXD hypervisors: Laravel backend, Next.js frontend, Reverb WebSockets, MySQL and Redis, packaged with Docker Compose.

## Quick start

```bash
make secrets    # generate .env.development and .env.production
make up-dev     # development: http://localhost
make up-prod    # production:  https://localhost
```

Requires Docker 24+, Docker Compose v2 and GNU Make. Run `make help` for all targets.

## Documentation

| Document | Contents |
|---|---|
| [Getting started](docs/getting-started.md) | Prerequisites, secrets, first run |
| [Development](docs/development.md) | Dev services, seeders, demo data, ports |
| [Production](docs/production.md) | Build, start, admin account, operations |
| [Configuration](docs/configuration.md) | Environment variables, ports, Reverb, TLS, safety checks, key rotation |
| [Commands](docs/commands.md) | Makefile, Docker Compose, artisan, DB and Redis commands |
| [Testing](docs/testing.md) | Unit and real-infrastructure integration tests |
| [Manual testing](docs/manual-testing.md) | Manual end-to-end test scenarios and API checks via cURL |
| [Snapshots](docs/snapshots.md) | Manual VM/container snapshots, states, quotas, API and backup extension points |
| [Interactive Console](docs/console.md) | Interactive xterm.js/noVNC web console, Redis tickets, WebSocket proxy, privacy model |
| [Roadmap](ROADMAP.md) | Upcoming architectural features and version 1.1.x noVNC roadmap |
| [Troubleshooting](docs/troubleshooting.md) | Common errors and fixes |
| [Architecture](docs/architecture.md) | Backend, frontend, API, WebSockets, data flows |

Additional architecture notes: [platform-architecture-en.md](platform-architecture-en.md), [platform-architecture-bg.md](platform-architecture-bg.md).

## License

[MIT](LICENSE)
